<?php

use App\Sesame\Accounts;
use App\Sesame\Delivery\DeliverEvents;
use App\Sesame\Delivery\SweepDue;
use App\Sesame\Delivery\Sweeper;
use App\Sesame\Ingest\AccountDirectory;
use App\Sesame\Ingest\IngestBuffer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * Sesame runs without a scheduler so its environment can hibernate: the
 * after-response drain moves the buffer to Postgres, delayed queue jobs wake
 * sweeps when events fall due, and inbound requests repair the rest.
 */
beforeEach(function (): void {
    sesameSetUp();
    $created = Accounts::create('wake', 'https://crm.test/hook', 'healthy', 1000, 5);
    $this->account = $created['account'];
    Accounts::confirm($this->account->refresh(), (string) $this->account->subscription_token, $this->account->topic_arn);
    $this->akid = $created['access_key_id'];
    AccountDirectory::forget();
});

/** @param array<string, array{0: int, 1: int}> $timing seconds */
function sendSome(object $test, int $count, array $timing = []): void
{
    $test->account->forceFill(['timing_overrides' => $timing + ['delivery' => [0, 0]], 'rate_overrides' => ['hard_bounce' => 0, 'soft_bounce' => 0, 'complaint' => 0, 'delay' => 0, 'reject' => 0, 'duplicate' => 0, 'out_of_order' => 0]])->save();
    AccountDirectory::forget();
    $client = sesClient($test, $test->akid);
    for ($i = 1; $i <= $count; $i++) {
        $client->sendEmail([
            'Destination' => ['ToAddresses' => ["success+{$i}@load.crm.test"]],
            'Content' => ['Raw' => ['Data' => "From: g@load.crm.test\r\nTo: success+{$i}@load.crm.test\r\nSubject: s\r\n\r\nb\r\n"]],
        ]);
    }
}

it('schedules nothing, so the environment can hibernate', function (): void {
    expect(resolve(Schedule::class)->events())->toBe([]);
});

it('drains every buffered send it can claim after any request, in bounded batches', function (): void {
    holdDrains($this);
    sendSome($this, 30);
    expect(IngestBuffer::depth())->toBe(30);

    // The budget bounds one drain: with no time left it moves a single batch.
    expect(IngestBuffer::drain(batch: 7, maxSeconds: 0))->toBe(7)
        ->and(IngestBuffer::depth())->toBe(23);

    // While another worker owns the buffer, a drain takes nothing and returns at once.
    $owner = fopen(IngestBuffer::path().'.drain.lock', 'c');
    flock($owner, LOCK_EX);
    expect(IngestBuffer::drain())->toBe(0)->and(IngestBuffer::depth())->toBe(23);
    flock($owner, LOCK_UN);
    fclose($owner);

    // Any request, not only a SendEmail, drains everything left after its response.
    $this->app->forgetInstance(DeferredCallbackCollection::class);
    config(['sesame.buffer.drain_batch' => 4]);
    $this->get("/sns/{$this->account->id}/cert.pem")->assertOk();

    expect(IngestBuffer::depth())->toBe(0)
        ->and(DB::table('sesame_messages')->count())->toBe(30)
        ->and((int) DB::table('sesame_buffers')->where('id', IngestBuffer::id())->value('drained_through_seq'))->toBe(30);
});

it('queues one delayed wakeup per second that has events due, after they are committed', function (): void {
    Queue::fake([SweepDue::class]);
    sendSome($this, 5, ['delivery' => [3, 3]]);

    $delays = Queue::pushed(SweepDue::class)->map(fn (SweepDue $job): int => $job->delay)->sort()->values();
    $events = DB::table('sesame_events')->where('type', '<>', 'SubscriptionConfirmation')->get();
    $latestDue = $events->max(fn ($e) => (int) CarbonImmutable::parse($e->due_at)->getTimestampMs());

    // Five sends, ten events: a handful of whole-second wakeups, not ten jobs.
    expect($events)->toHaveCount(10)
        ->and($delays->count())->toBeLessThanOrEqual(5)
        // The last wakeup lands after the last due event, never before it.
        ->and(Queue::pushed(SweepDue::class)->max(fn (SweepDue $job): int => $job->second) * 1000)->toBeGreaterThan($latestDue)
        ->and($delays->last())->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(5);
});

it('chains a wakeup further out than the queue allows until its time comes', function (): void {
    Queue::fake([SweepDue::class, DeliverEvents::class]);
    config(['sesame.delivery.max_delay_seconds' => 900]);
    sendSome($this, 1, ['delivery' => [3600, 3600]]); // a final due an hour out

    $far = Queue::pushed(SweepDue::class)->sortByDesc(fn (SweepDue $job): int => $job->second)->first();
    expect($far->delay)->toBe(900);

    // It arrives early: it sweeps nothing and re-queues itself for the rest.
    $before = Queue::pushed(SweepDue::class)->count();
    app()->call([$far, 'handle']);
    $next = Queue::pushed(SweepDue::class)->last();
    expect(Queue::pushed(SweepDue::class))->toHaveCount($before + 1)
        ->and($next)->not->toBe($far)
        ->and($next->second)->toBe($far->second)
        ->and($next->delay)->toBe(900)
        ->and(Queue::pushed(DeliverEvents::class))->toHaveCount(0);
});

it('wakes a sweep when a lease runs out, so a lost delivery job only delays its events', function (): void {
    sendSome($this, 3);
    Queue::fake([SweepDue::class, DeliverEvents::class]);
    config(['sesame.delivery.lease_seconds' => 60]);

    expect(resolve(Sweeper::class)->sweep())->toBeGreaterThan(0);

    expect(Queue::pushed(SweepDue::class)->map(fn (SweepDue $job): int => $job->delay)->all())->toContain(61);
});

it('repairs overdue events whose wakeup was lost on the next inbound request, at most once per interval', function (): void {
    Queue::fake([SweepDue::class, DeliverEvents::class]); // every wakeup is "lost"
    sendSome($this, 2);
    expect(DB::table('sesame_events')->where('status', 'pending')->count())->toBe(5); // 2 x (Send, Delivery) + confirmation
    expect(Queue::pushed(DeliverEvents::class))->toHaveCount(0);

    config(['sesame.housekeeping_seconds' => 10]);
    $this->get("/sns/{$this->account->id}/cert.pem")->assertOk();
    $this->get("/sns/{$this->account->id}/cert.pem")->assertOk();

    $leased = Queue::pushed(DeliverEvents::class)->flatMap(fn (DeliverEvents $job): array => $job->eventIds);
    expect($leased)->toHaveCount(5)
        ->and(Queue::pushed(DeliverEvents::class))->toHaveCount(1) // the second request was inside the interval
        ->and(DB::table('sesame_buffers')->where('id', IngestBuffer::id())->value('last_seen_at'))->not->toBeNull();
});
