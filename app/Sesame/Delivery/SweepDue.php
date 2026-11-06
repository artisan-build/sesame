<?php

namespace App\Sesame\Delivery;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;

/**
 * A wakeup: once its second has come, hand every due event to delivery jobs.
 *
 * Queued with a delay by Wakeups. A wakeup further away than the queue's
 * longest delay (15 minutes on SQS) arrives early and re-queues itself for
 * the rest, so a complaint due an hour out is a short chain of jobs, not a
 * timer anyone has to keep. Running twice, or after another sweep took the
 * events, is harmless: leases keep claims disjoint.
 */
final class SweepDue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $second) {}

    public function handle(Sweeper $sweeper): void
    {
        if ((int) floor(microtime(true) * 1000) < $this->second * 1000) {
            // A sync queue cannot wait: re-queueing would recurse. Only local
            // setups use one, and Housekeeping still sweeps overdue events.
            if (! $this->job instanceof SyncJob) {
                Wakeups::dispatch($this->second);
            }

            return;
        }

        if ($sweeper->sweep() >= (int) config('sesame.delivery.sweep_limit')) {
            // More is due than one sweep hands out: carry on in a fresh job.
            Wakeups::dispatch(intdiv((int) floor(microtime(true) * 1000), 1000));
        }
    }
}
