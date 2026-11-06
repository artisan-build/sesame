<?php

use App\CrmHarness\LoadContacts;
use App\CrmHarness\SendLoadMessage;
use App\Models\SesameAccount;
use App\Sesame\Accounts;
use App\Sesame\Delivery\DeliverEvents;
use App\Sesame\Delivery\DeliveryPool;
use App\Sesame\Delivery\Sweeper;
use App\Sesame\Ingest\AccountDirectory;
use App\Sesame\Ingest\IngestBuffer;
use Aws\Sns\MessageValidator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

/*
 * The whole loop in one process: harness send job -> Laravel Mailable ->
 * stock ses-v2 transport -> Sesame SendEmail -> SQLite ingest buffer -> drain
 * to Postgres -> sweep -> queued DeliverEvents -> signed SNS POST -> harness
 * webhook (real MessageValidator) -> queued processing -> reconciliation
 * against Sesame's ground truth.
 */
beforeEach(function (): void {
    sesameSetUp();
    $test = $this;
    // The harness's outbound HTTP (SubscribeURL GET, truth API) reaches Sesame in-process.
    Http::fake(['sesame.test/*' => function ($request) use ($test) {
        $uri = parse_url($request->url());
        $response = $test->withHeaders(array_map(fn ($v) => implode(', ', $v), $request->headers()))
            ->json($request->method(), $uri['path'].(isset($uri['query']) ? '?'.$uri['query'] : ''), $request->data());

        return Http::response($response->getContent(), $response->getStatusCode(), ['Content-Type' => $response->headers->get('Content-Type')]);
    }]);
    config([
        'services.ses.endpoint' => 'https://sesame.test',
        'mail.mailers.crm-ses.http_handler' => inProcessHandler($this, true),
        'sesame.delivery.retry' => ['retries' => 3, 'delay_seconds' => 0.05],
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
    AccountDirectory::forget();
    $path = tempnam(sys_get_temp_dir(), 'creds');
    file_put_contents($path, json_encode(['run_id' => $account->current_run_id] + $created));
    Artisan::call('crm:harness:connect', ['credentials' => $path]);

    return $account->refresh();
}

function sendLoad(int $count, string $run = 'test-run'): void
{
    LoadContacts::seed($count);
    foreach (DB::table('harness_contacts')->orderBy('id')->limit($count)->pluck('id') as $contactId) {
        dispatch(new SendLoadMessage(DB::table('harness_outbound_messages')->insertGetId(['contact_id' => $contactId, 'load_run' => $run, 'dispatched_at' => now()])));
    }
}

it('delivers through the container-built pool a queue worker gets', function (): void {
    config(['sesame.delivery.concurrency' => 7]);

    expect(resolve(DeliveryPool::class)->capacity())->toBe(7);
});

it('confirms the subscription before delivering anything', function (): void {
    $account = connectHarness();
    sendLoad(3);

    expect($account->subscription_status)->toBe('pending');
    runDelivery($this);

    $account->refresh();
    expect($account->subscription_status)->toBe('confirmed')
        ->and(DB::table('harness_sns_receipts')->where('type', 'SubscriptionConfirmation')->count())->toBe(1)
        // Every event was held until confirmation and then delivered.
        ->and(DB::table('sesame_events')->where('type', '<>', 'SubscriptionConfirmation')->where('status', '<>', 'delivered')->count())->toBe(0);
});

it('closes the loop with every event reconciled, including forced bounces and complaints', function (): void {
    connectHarness(['duplicate' => 30, 'out_of_order' => 30, 'delay' => 20, 'soft_bounce' => 10]);
    sendLoad(1000); // contact 200, 400, ... force bounces; 500 and 1000 force complaints

    runDelivery($this, 240); // a safety bound only: CI runners took 99 s where an orb takes 50

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

it('catches a CRM that loses events: a webhook answering 403 drops them and reconciliation fails', function (): void {
    connectHarness();
    runDelivery($this); // confirm first
    sendLoad(5);
    // Break the CRM's validator after confirmation: every notification now fails signature checks.
    config(['services.sns.cert_host_pattern' => '/^nope$/']);
    app()->instance(MessageValidator::class, new MessageValidator(fn () => '', '/^nope$/'));

    runDelivery($this);

    expect(DB::table('sesame_events')->where('status', 'dropped')->where('last_status', 403)->count())->toBe(10) // Send + Delivery each
        ->and(DB::table('sesame_delivery_attempts')->where('http_status', 403)->count())->toBe(10) // 4xx is never retried
        ->and(Artisan::call('crm:reconcile'))->toBe(1);
});

it('retries 5xx with the SNS policy and then drops', function (): void {
    connectHarness();
    runDelivery($this);
    sendLoad(1);
    // The webhook's storage is "down".
    Schema::rename('harness_sns_receipts', 'harness_sns_receipts_down');

    runDelivery($this, 10);

    Schema::rename('harness_sns_receipts_down', 'harness_sns_receipts');
    $send = DB::table('sesame_events')->where('type', 'Send')->first();
    expect($send->status)->toBe('dropped')
        ->and((int) $send->attempts)->toBe(4) // first try + 3 retries
        ->and(DB::table('sesame_delivery_attempts')->where('event_id', $send->id)->pluck('http_status')->unique()->all())->toBe([503]);
});

it('redelivers events whose delivery job was lost once the lease runs out, and a late job settles nothing', function (): void {
    config(['sesame.delivery.lease_seconds' => 0.3]);
    connectHarness();
    runDelivery($this); // subscription confirmed
    sendLoad(20);
    IngestBuffer::drain();

    // A sweep hands every event to jobs that never run: the worker died.
    $queue = resolve('queue');
    Queue::fake([DeliverEvents::class]);
    expect(resolve(Sweeper::class)->sweep())->toBe(40);
    $lost = Queue::pushed(DeliverEvents::class)->all();
    // While leased, no other sweep takes them.
    expect(resolve(Sweeper::class)->sweep())->toBe(0);
    Queue::swap($queue); // back to the sync connection

    Sleep::usleep(400_000); // the lease runs out
    runDelivery($this);

    expect(DB::table('sesame_events')->where('type', '<>', 'SubscriptionConfirmation')->where('status', 'delivered')->count())->toBe(40)
        ->and(DB::table('sesame_delivery_attempts')->count())->toBe(41);

    // The lost jobs turn up late (queue redelivery): their lease tokens are stale, so nothing is posted or settled twice.
    foreach ($lost as $job) {
        app()->call([$job, 'handle']);
    }
    expect(DB::table('sesame_delivery_attempts')->count())->toBe(41)
        ->and(DB::table('harness_message_events')->count())->toBe(40)
        ->and(Artisan::call('crm:reconcile'))->toBe(0);
});

it('reports sends lost with a replaced ingest buffer as simulator losses, apart from CRM mismatches', function (): void {
    connectHarness();
    runDelivery($this); // subscription confirmed
    holdDrains($this);
    sendLoad(20, 'before-deploy'); // acknowledged, still only in the instance's buffer

    // A deploy replaces the instance: its temporary directory, and the buffer with it, is gone.
    $old = IngestBuffer::id();
    File::deleteDirectory(dirname(IngestBuffer::path()));
    IngestBuffer::reset();
    DB::table('sesame_buffers')->where('id', $old)->update(['last_seen_at' => now()->subMinute()]);

    sendLoad(5, 'after-deploy'); // served by the new instance's fresh buffer
    expect(IngestBuffer::id())->not->toBe($old);
    runDelivery($this);

    expect(DB::table('harness_outbound_messages')->where('state', 'accepted')->count())->toBe(25)
        ->and(DB::table('sesame_messages')->count())->toBe(5);
    $exit = Artisan::call('crm:reconcile', ['--json' => $report = tempnam(sys_get_temp_dir(), 'rec')]);
    $result = json_decode(file_get_contents($report), true);

    expect($exit)->toBe(2) // the simulator lost sends; the CRM did nothing wrong
        ->and($result['reconciliation']['mismatches'])->toBe(0)
        ->and($result['reconciliation']['messages_compared'])->toBe(5)
        ->and($result['simulator_losses']['lost_in_ingest_buffer'])->toBe(20)
        ->and($result['simulator_losses']['examples'][0]['buffer'])->toBe($old)
        ->and($result['simulator_losses']['examples'][0]['drained_through'])->toBe(0);
});

it('still fails reconciliation for a MessageId Sesame never issued', function (): void {
    connectHarness();
    runDelivery($this);
    sendLoad(2);
    runDelivery($this);
    DB::table('harness_outbound_messages')->orderBy('id')->limit(1)->update(['provider_message_id' => '0100000000000000-00000000-0000-0000-0000-000000000000-000001']);

    expect(Artisan::call('crm:reconcile'))->toBe(1);
});

it('never resends accepted work and skips suppressed contacts', function (): void {
    connectHarness();
    runDelivery($this);
    sendLoad(200); // contact 200 is a forced hard bounce
    runDelivery($this);
    $first = DB::table('harness_outbound_messages')->orderBy('id')->first();

    dispatch(new SendLoadMessage($first->id)); // queue redelivery
    $bounced = DB::table('harness_contacts')->where('email', 'bounce+200@load.crm.test')->value('id');
    $again = DB::table('harness_outbound_messages')->insertGetId(['contact_id' => $bounced, 'load_run' => 'second', 'dispatched_at' => now()]);
    dispatch(new SendLoadMessage($again));

    expect(DB::table('sesame_messages')->count())->toBe(200)
        ->and(DB::table('harness_outbound_messages')->where('id', $again)->value('state'))->toBe('skipped_suppressed');
});
