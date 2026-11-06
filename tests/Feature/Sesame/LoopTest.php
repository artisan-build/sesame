<?php

use App\CrmHarness\LoadContacts;
use App\CrmHarness\SendLoadMessage;
use App\Models\SesameAccount;
use App\Sesame\Accounts;
use App\Sesame\Dataplane\Dataplane;
use App\Sesame\Dataplane\DeliveryPool;
use App\Sesame\Ingest\AccountDirectory;
use Aws\Sns\MessageValidator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * The whole loop in one process: harness send job -> Laravel Mailable ->
 * stock ses-v2 transport -> Sesame SendEmail -> WAL -> data plane -> signed
 * SNS POST -> harness webhook (real MessageValidator) -> queued processing ->
 * reconciliation against Sesame's ground truth.
 */
beforeEach(function () {
    sesameSetUp();
    $test = $this;
    // The harness's outbound HTTP (SubscribeURL GET, truth API) reaches Sesame in-process.
    Http::fake(['sesame.test/*' => function ($request) use ($test) {
        $uri = parse_url($request->url());
        $response = $test->withHeaders(array_map(fn ($v) => implode(', ', $v), $request->headers()))
            ->get($uri['path'].(isset($uri['query']) ? '?'.$uri['query'] : ''));

        return Http::response($response->getContent(), $response->getStatusCode(), ['Content-Type' => $response->headers->get('Content-Type')]);
    }]);
    config([
        'services.ses.endpoint' => 'https://sesame.test',
        'mail.mailers.crm-ses.http_handler' => inProcessHandler($this, true),
        'sesame.dataplane.retry' => ['retries' => 3, 'delay_seconds' => 0.05],
    ]);
});

/** @param array<string, float> $rates */
function connectHarness(array $rates = [], int $seed = 11): SesameAccount
{
    $created = Accounts::create('growth-staging', 'https://crm.test/crm-harness/sns', 'healthy', 1000, $seed);
    $account = $created['account'];
    $account->forceFill([
        'rate_overrides' => $rates,
        // Compressed to zero so the test does not wait on the clock.
        'timing_overrides' => ['delivery' => [0, 0], 'bounce' => [0, 0], 'complaint' => [0, 0], 'delay_final' => [0, 0]],
    ])->save();
    AccountDirectory::write();
    $path = tempnam(sys_get_temp_dir(), 'creds');
    file_put_contents($path, json_encode(['run_id' => $account->current_run_id] + $created));
    Artisan::call('crm:harness:connect', ['credentials' => $path]);

    return $account->refresh();
}

function runDataplane(TestCase $test, float $seconds = 15, ?Dataplane $dataplane = null): Dataplane
{
    $dataplane ??= new Dataplane(new DeliveryPool(16, 5, inProcessHandler($test)));
    $dataplane->boot();
    $deadline = microtime(true) + $seconds;
    $settled = 0;
    while (microtime(true) < $deadline) {
        $dataplane->reloadAccounts(); // NOTIFY never fires inside the test transaction
        $worked = $dataplane->tick();
        $settled = ($worked || $dataplane->pending() > 0) ? 0 : $settled + 1;
        if ($settled >= 5) {
            break;
        }
        usleep(5000);
    }

    return $dataplane;
}

function sendLoad(int $count, string $run = 'test-run'): void
{
    LoadContacts::seed($count);
    foreach (DB::table('harness_contacts')->orderBy('id')->limit($count)->pluck('id') as $contactId) {
        SendLoadMessage::dispatch(DB::table('harness_outbound_messages')->insertGetId(['contact_id' => $contactId, 'load_run' => $run, 'dispatched_at' => now()]));
    }
}

it('confirms the subscription before delivering anything', function () {
    $account = connectHarness();
    sendLoad(3);

    expect($account->subscription_status)->toBe('pending');
    runDataplane($this);

    $account->refresh();
    expect($account->subscription_status)->toBe('confirmed')
        ->and(DB::table('harness_sns_receipts')->where('type', 'SubscriptionConfirmation')->count())->toBe(1)
        // Every event was held until confirmation and then delivered.
        ->and(DB::table('sesame_events')->where('type', '<>', 'SubscriptionConfirmation')->where('status', '<>', 'delivered')->count())->toBe(0);
});

it('closes the loop with every event reconciled, including forced bounces and complaints', function () {
    connectHarness(['duplicate' => 30, 'out_of_order' => 30, 'delay' => 20, 'soft_bounce' => 10]);
    sendLoad(1000); // contact 200, 400, ... force bounces; 500 and 1000 force complaints

    runDataplane($this, 60);

    expect(DB::table('harness_outbound_messages')->where('state', 'accepted')->count())->toBe(1000);
    $exit = Artisan::call('crm:reconcile', ['--json' => $report = tempnam(sys_get_temp_dir(), 'rec')]);
    $result = json_decode(file_get_contents($report), true);

    expect($result['reconciliation']['mismatches'])->toBe(0, json_encode($result['reconciliation']['examples']))
        ->and($result['reconciliation']['messages_compared'])->toBe(1000)
        ->and($exit)->toBe(0)
        ->and($result['simulator']['events']['by_status']['dropped'])->toBe(0)
        // Forced outcomes landed in CRM state and suppressed their contacts.
        ->and(DB::table('harness_contacts')->where('email', 'bounce+200@load.crm.test')->value('suppression_reason'))->toBe('hard_bounce')
        ->and(DB::table('harness_contacts')->where('email', 'complaint+500@load.crm.test')->value('suppression_reason'))->toBe('complaint')
        // Duplicates were delivered by Sesame but recorded once by the CRM.
        ->and(DB::table('sesame_events')->whereNotNull('duplicate_of')->count())->toBeGreaterThan(50)
        ->and(DB::table('harness_message_events')->count())->toBe(DB::table('sesame_events')->whereNull('duplicate_of')->where('type', '<>', 'SubscriptionConfirmation')->count());
});

it('catches a CRM that loses events: a webhook answering 403 drops them and reconciliation fails', function () {
    connectHarness();
    runDataplane($this); // confirm first
    sendLoad(5);
    // Break the CRM's validator after confirmation: every notification now fails signature checks.
    config(['services.sns.cert_host_pattern' => '/^nope$/']);
    app()->instance(MessageValidator::class, new MessageValidator(fn () => '', '/^nope$/'));

    runDataplane($this);

    expect(DB::table('sesame_events')->where('status', 'dropped')->where('last_status', 403)->count())->toBe(10) // Send + Delivery each
        ->and(DB::table('sesame_delivery_attempts')->where('http_status', 403)->count())->toBe(10) // 4xx is never retried
        ->and(Artisan::call('crm:reconcile'))->toBe(1);
});

it('retries 5xx with the SNS policy and then drops', function () {
    connectHarness();
    runDataplane($this);
    sendLoad(1);
    // The webhook's storage is "down".
    Schema::rename('harness_sns_receipts', 'harness_sns_receipts_down');

    runDataplane($this, 10);

    Schema::rename('harness_sns_receipts_down', 'harness_sns_receipts');
    $send = DB::table('sesame_events')->where('type', 'Send')->first();
    expect($send->status)->toBe('dropped')
        ->and((int) $send->attempts)->toBe(4) // first try + 3 retries
        ->and(DB::table('sesame_delivery_attempts')->where('event_id', $send->id)->pluck('http_status')->unique()->all())->toBe([503]);
});

it('resumes after a restart: WAL records ingest once and pending events are rescheduled', function () {
    connectHarness();
    runDataplane($this); // subscription confirmed
    sendLoad(20);

    // A data plane that ingests the WAL and then "crashes" before delivering anything.
    $crashing = new Dataplane(new DeliveryPool(0, 5, inProcessHandler($this)));
    $crashing->boot();
    $crashing->tick();
    expect(DB::table('sesame_messages')->count())->toBe(20)
        ->and(DB::table('sesame_events')->where('status', 'pending')->count())->toBe(40);

    // A fresh process: rebuilds the wheel from Postgres, re-reads the WAL from the checkpoint.
    runDataplane($this);

    expect(DB::table('sesame_messages')->count())->toBe(20)
        ->and(DB::table('sesame_events')->where('type', '<>', 'SubscriptionConfirmation')->count())->toBe(40)
        ->and(DB::table('sesame_events')->where('status', 'delivered')->where('type', '<>', 'SubscriptionConfirmation')->count())->toBe(40)
        ->and(Artisan::call('crm:reconcile'))->toBe(0);
});

it('never resends accepted work and skips suppressed contacts', function () {
    connectHarness();
    runDataplane($this);
    sendLoad(200); // contact 200 is a forced hard bounce
    runDataplane($this);
    $first = DB::table('harness_outbound_messages')->orderBy('id')->first();

    SendLoadMessage::dispatch($first->id); // queue redelivery
    $bounced = DB::table('harness_contacts')->where('email', 'bounce+200@load.crm.test')->value('id');
    $again = DB::table('harness_outbound_messages')->insertGetId(['contact_id' => $bounced, 'load_run' => 'second', 'dispatched_at' => now()]);
    SendLoadMessage::dispatch($again);

    expect(DB::table('sesame_messages')->count())->toBe(200)
        ->and(DB::table('harness_outbound_messages')->where('id', $again)->value('state'))->toBe('skipped_suppressed');
});
