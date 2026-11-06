<?php

namespace App\Console\Commands;

use App\Models\SesameAccount;
use App\Sesame\Accounts;
use App\Sesame\Ingest\AccountDirectory;
use Illuminate\Console\Command;

class SesameRunStart extends Command
{
    protected $signature = 'sesame:run:start
        {account : Account name}
        {--seed= : Replay a run by passing its seed}
        {--preset= : Override the account preset for this run}
        {--label=}';

    protected $description = 'End the account\'s current run and start a new one.';

    public function handle(): int
    {
        $account = SesameAccount::where('name', $this->argument('account'))->firstOrFail();
        $run = Accounts::startRun(
            $account,
            $this->option('seed') !== null ? (int) $this->option('seed') : null,
            $this->option('preset') ?: null,
            $this->option('label') ?: null,
        );
        AccountDirectory::write();
        $this->line((string) json_encode(['run_id' => $run->id, 'seed' => $run->seed, 'preset' => $run->preset], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
