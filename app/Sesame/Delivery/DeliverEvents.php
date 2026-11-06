<?php

namespace App\Sesame\Delivery;

use App\Sesame\Sns\SesEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Posts one leased batch of events to their webhooks, concurrently, and
 * records each attempt with SNS's HTTP retry rules.
 *
 * Runs on a managed queue worker. It keeps nothing between runs and writes
 * only rows still leased to its token, so redelivery of the job, a worker
 * replaced mid-batch, or a sweep that re-leased the events after the lease
 * ran out never settles an event twice. The batch finishes inside the
 * delivery timeout, well under a Flex worker's 90-second job limit.
 */
final class DeliverEvents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** A failed run is retried by the lease running out, not by the queue. */
    public int $tries = 1;

    /** @param list<string> $eventIds */
    public function __construct(public string $leaseToken, public array $eventIds) {}

    public function handle(DeliveryPool $pool): void
    {
        $events = DB::table('sesame_events')->whereIn('id', $this->eventIds)
            ->where('lease_token', $this->leaseToken)->where('status', 'pending')->get();
        if ($events->isEmpty()) {
            return;
        }
        $accounts = DB::table('sesame_accounts')->whereIn('id', $events->pluck('account_id')->unique()->all())->get()->keyBy('id');
        $nowMs = self::nowMs();

        $byId = [];
        foreach ($events as $event) {
            $account = $accounts[$event->account_id] ?? null;
            if ($account === null || $account->webhook_url === null) {
                continue;
            }
            [$headers, $body] = Envelope::for($event, $account, $nowMs);
            $byId[$event->id] = $event;
            $pool->post($event->id, (string) $account->webhook_url, $headers, $body);
        }

        $this->settle($byId, $pool->drain((float) config('sesame.delivery.timeout_seconds') + 5));
    }

    /**
     * @param  array<string, object>  $events
     * @param  list<array{key: string, status: ?int, error: ?string, started_ms: float, duration_ms: float}>  $results
     */
    private function settle(array $events, array $results): void
    {
        $retries = (int) config('sesame.delivery.retry.retries');
        $delayMs = (int) round((float) config('sesame.delivery.retry.delay_seconds') * 1000);
        $attempts = [];

        DB::transaction(function () use ($events, $results, $retries, $delayMs, &$attempts): void {
            foreach ($results as $result) {
                $event = $events[$result['key']] ?? null;
                if ($event === null) {
                    continue;
                }
                $attempt = (int) $event->attempts + 1;
                $status = $result['status'];
                $update = [
                    'attempts' => $attempt, 'last_status' => $status, 'last_error' => $result['error'],
                    'first_attempt_at' => $event->first_attempt_at ?? SesEvent::iso((int) $result['started_ms']),
                    'lease_token' => null, 'lease_until' => null,
                ];
                if ($status !== null && $status >= 200 && $status < 300) {
                    $update += ['status' => 'delivered', 'delivered_at' => SesEvent::iso((int) ($result['started_ms'] + $result['duration_ms'])), 'latency_ms' => round($result['duration_ms'], 3)];
                } elseif (($status === null || $status >= 500 || $status === 429) && $attempt <= $retries) {
                    // SNS retries only 5xx, 429 and transport failures.
                    $update += ['status' => 'pending', 'due_at' => SesEvent::iso(self::nowMs() + $delayMs)];
                } else {
                    // Any other response, or retries exhausted: the event is gone, and Sesame reports it.
                    $update += ['status' => 'dropped'];
                }

                $written = DB::table('sesame_events')->where('id', $event->id)->where('lease_token', $this->leaseToken)->where('status', 'pending')->update($update);
                if ($written === 1) {
                    $attempts[] = [
                        'event_id' => $event->id, 'attempt' => $attempt, 'started_at' => SesEvent::iso((int) $result['started_ms']),
                        'duration_ms' => round($result['duration_ms'], 3), 'http_status' => $status, 'error' => $result['error'],
                    ];
                }
            }
            foreach (array_chunk($attempts, 500) as $chunk) {
                DB::table('sesame_delivery_attempts')->insert($chunk);
            }
        });
    }

    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
