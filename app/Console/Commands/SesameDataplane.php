<?php

namespace App\Console\Commands;

use App\Sesame\Dataplane\Dataplane;
use Illuminate\Console\Command;

class SesameDataplane extends Command
{
    protected $signature = 'sesame:dataplane {--max-seconds=0 : Stop after this many seconds (0 runs until signalled)}';

    protected $description = 'Run the data plane: WAL ingest, timing wheel, signing and webhook delivery.';

    public function handle(): int
    {
        $stop = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$stop): void {
                $stop = true;
            });
            pcntl_signal(SIGINT, function () use (&$stop): void {
                $stop = true;
            });
        }
        $deadline = (int) $this->option('max-seconds') > 0 ? microtime(true) + (int) $this->option('max-seconds') : null;
        $this->info('Sesame data plane running.');

        Dataplane::fromConfig()->run(fn (): bool => $stop || ($deadline !== null && microtime(true) >= $deadline));

        $this->info('Sesame data plane stopped.');

        return self::SUCCESS;
    }
}
