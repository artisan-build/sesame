<?php

namespace App\Sesame\Delivery;

use App\Sesame\Sns\SesEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Hands due events to delivery jobs. SweepDue wakeups run it when events fall
 * due, and Housekeeping runs it to repair anything overdue.
 *
 * A sweep leases each due event to one batch token for `lease_seconds`.
 * Only the job holding that token may settle the event, so a job that is
 * lost, crashes or runs twice cannot settle it twice; when a lease runs
 * out the event is due again and the wakeup scheduled for that moment
 * hands it to a new job.
 * Claims use FOR UPDATE SKIP LOCKED, so overlapping sweeps on several
 * replicas take disjoint events. Nothing is held in memory between sweeps.
 *
 * Events for an account whose SNS subscription is not confirmed wait in
 * place, except the SubscriptionConfirmation itself.
 */
final class Sweeper
{
    /** @return int events handed to delivery jobs */
    public function sweep(?int $nowMs = null): int
    {
        $nowMs ??= (int) floor(microtime(true) * 1000);
        $batch = max(1, (int) config('sesame.delivery.batch'));
        $limit = max($batch, (int) config('sesame.delivery.sweep_limit'));
        $leaseMs = (int) round((float) config('sesame.delivery.lease_seconds') * 1000);
        $now = SesEvent::iso($nowMs);
        $claimed = 0;

        while ($claimed < $limit) {
            $token = (string) Str::uuid7();
            $ids = array_map(fn (object $row): string => $row->id, DB::select(
                <<<'SQL'
                UPDATE sesame_events SET lease_token = ?, lease_until = ?
                WHERE id IN (
                    SELECT e.id FROM sesame_events e
                    JOIN sesame_accounts a ON a.id = e.account_id
                    WHERE e.status = 'pending' AND e.due_at <= ?
                      AND (e.lease_until IS NULL OR e.lease_until <= ?)
                      AND a.webhook_url IS NOT NULL
                      AND (a.subscription_status = 'confirmed' OR e.type = 'SubscriptionConfirmation')
                    ORDER BY e.due_at
                    LIMIT ?
                    FOR UPDATE OF e SKIP LOCKED
                )
                RETURNING id
                SQL,
                [$token, SesEvent::iso($nowMs + $leaseMs), $now, $now, min($batch, $limit - $claimed)],
            ));
            if ($ids === []) {
                break;
            }
            dispatch(new DeliverEvents($token, $ids));
            $claimed += count($ids);
            if (count($ids) < $batch) {
                break;
            }
        }

        if ($claimed > 0) {
            // If a job is lost, its events are due again when the lease runs
            // out: wake a sweep then, so no scheduler has to notice.
            Wakeups::at([$nowMs + $leaseMs]);
        }

        return $claimed;
    }
}
