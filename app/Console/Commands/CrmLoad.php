<?php

namespace App\Console\Commands;

use App\CrmHarness\Clock;
use App\CrmHarness\LoadContacts;
use App\CrmHarness\SendLoadMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * LOCAL CRM-COMPATIBLE HARNESS stand-in for Growth's `crm:load`. Dispatches
 * one queued send per message at a fixed rate; the queued job goes through
 * the real Mailable and stock ses-v2 transport.
 */
class CrmLoad extends Command
{
    protected $signature = 'crm:load
        {--rate=30 : Sends per second on the plateau}
        {--duration=900 : Plateau length in seconds}
        {--run= : Label stored on each outbound row}';

    protected $description = '[local harness] Dispatch a paced plateau of sends through the queue, Mailable and ses-v2 transport.';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('crm:load refuses to run in production.');

            return self::FAILURE;
        }
        $rate = max(1, (int) $this->option('rate'));
        $total = $rate * max(1, (int) $this->option('duration'));
        $run = (string) ($this->option('run') ?: 'load-'.now()->format('YmdHis'));

        // Suppressed contacts are never sent to again, so seed enough to cover them.
        LoadContacts::seed($total + DB::table('harness_contacts')->whereNotNull('suppressed_at')->count());
        $contacts = DB::table('harness_contacts')->whereNull('suppressed_at')->orderBy('id')->limit($total)->pluck('id')->all();
        if (count($contacts) < $total) {
            $this->error('Not enough unsuppressed load contacts.');

            return self::FAILURE;
        }

        $this->info("Dispatching {$total} sends at {$rate}/s as run [{$run}].");
        $start = microtime(true);
        $dispatched = $printed = 0;
        while ($dispatched < $total) {
            $due = min($total, (int) floor((microtime(true) - $start) * $rate) + 1);
            while ($dispatched < $due) {
                $id = DB::table('harness_outbound_messages')->insertGetId([
                    'contact_id' => $contacts[$dispatched], 'load_run' => $run, 'dispatched_at' => Clock::now(),
                ]);
                SendLoadMessage::dispatch($id);
                $dispatched++;
            }
            if ($dispatched >= $printed + $rate * 60) {
                $printed = $dispatched;
                $this->line(sprintf('%5ds  %d dispatched', (int) (microtime(true) - $start), $dispatched));
            }
            usleep(2000);
        }
        $this->info(sprintf('Dispatched %d in %.1fs.', $dispatched, microtime(true) - $start));

        return self::SUCCESS;
    }
}
