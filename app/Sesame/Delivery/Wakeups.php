<?php

namespace App\Sesame\Delivery;

use Illuminate\Support\Facades\Cache;

/**
 * Delayed queue jobs that wake the sweeper when events fall due, in place of
 * a scheduler. Sesame has no scheduled tasks, so it can hibernate when idle:
 * the managed queue runs on its own compute and wakes for delayed jobs.
 *
 * Due times are grouped into whole seconds. The wakeup for second K sweeps
 * at or after K, and an event is filed under a K later than both its due
 * time and the moment it was committed, so the sweep always sees it. One
 * wakeup per second is enough however many events share it; the cache
 * remembers which seconds already have one (a race only queues a harmless
 * duplicate). A wakeup further out than the queue's longest delay re-queues
 * itself until its second comes (see SweepDue).
 *
 * Nothing here is the only path to delivery: every lease schedules a wakeup
 * for its expiry, and inbound requests repair anything overdue (see
 * Housekeeping), so a wakeup lost to a crash only delays its events.
 */
final class Wakeups
{
    /** @var array<int, true> seconds this process already scheduled (Octane keeps it between requests) */
    private static array $scheduled = [];

    /**
     * Make sure a sweep runs once each of these instants has passed.
     *
     * @param  iterable<int>  $dueMs  epoch milliseconds
     * @return int wakeups queued
     */
    public static function at(iterable $dueMs): int
    {
        $nowMs = self::nowMs();
        $nowSecond = intdiv($nowMs, 1000);
        $seconds = [];
        foreach ($dueMs as $ms) {
            $seconds[intdiv(max($ms, $nowMs), 1000) + 1] = true;
        }
        foreach (array_keys(self::$scheduled) as $second) {
            if ($second <= $nowSecond) {
                unset(self::$scheduled[$second]);
            }
        }
        $wanted = array_keys(array_diff_key($seconds, self::$scheduled));
        if ($wanted === []) {
            return 0;
        }

        $known = Cache::many(array_map(self::key(...), $wanted));
        $queued = 0;
        $remember = [];
        foreach ($wanted as $second) {
            self::$scheduled[$second] = true;
            if (($known[self::key($second)] ?? null) !== null) {
                continue;
            }
            self::dispatch($second);
            $remember[self::key($second)] = 1;
            $queued++;
        }
        if ($remember !== []) {
            // Queued first, remembered second: a crash in between only queues a duplicate later.
            Cache::putMany($remember, max(60, max($wanted) - $nowSecond + 60));
        }

        return $queued;
    }

    /** Queue the wakeup for $second, or as close to it as the queue's longest delay allows. */
    public static function dispatch(int $second): void
    {
        $delay = (int) ceil(($second * 1000 - self::nowMs()) / 1000);
        dispatch(new SweepDue($second))->delay(max(0, min($delay, (int) config('sesame.delivery.max_delay_seconds'))));
    }

    /** Forget what this process scheduled (tests). */
    public static function reset(): void
    {
        self::$scheduled = [];
    }

    private static function key(int $second): string
    {
        return 'sesame:wakeup:'.$second;
    }

    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
