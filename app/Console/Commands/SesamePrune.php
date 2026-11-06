<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SesamePrune extends Command
{
    protected $signature = 'sesame:prune';

    protected $description = 'Delete raw messages, events and attempts older than the retention window. Run summaries are kept.';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('sesame.retention_days'));
        $attempts = DB::table('sesame_delivery_attempts')->where('started_at', '<', $cutoff)->delete();
        $events = DB::table('sesame_events')->where('created_at', '<', $cutoff)->where('status', '<>', 'pending')->delete();
        $messages = DB::table('sesame_messages')->where('accepted_at', '<', $cutoff)->delete();
        $refusals = DB::table('sesame_send_refusals')->where('at', '<', $cutoff)->delete();
        $this->info("Pruned {$messages} messages, {$events} events, {$attempts} attempts, {$refusals} refusals older than {$cutoff->toIso8601String()}.");

        return self::SUCCESS;
    }
}
