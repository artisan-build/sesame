<?php

namespace App\Console\Commands;

use App\Sesame\Accounts;
use App\Sesame\Ingest\AccountDirectory;
use Illuminate\Console\Command;

class SesameAccountCreate extends Command
{
    protected $signature = 'sesame:account:create
        {name : Lowercase name, e.g. growth-staging}
        {--webhook= : SNS HTTP(S) endpoint; registering it sends a SubscriptionConfirmation}
        {--preset=healthy : healthy, review, paused or chaos}
        {--max-rate= : Max send rate per second; mirror the client\'s real SES quota}
        {--seed= : Seed for the first run}
        {--json : Print credentials as JSON}';

    protected $description = 'Create a simulated SES account and print its credentials once.';

    public function handle(): int
    {
        $created = Accounts::create(
            (string) $this->argument('name'),
            $this->option('webhook') ?: null,
            (string) $this->option('preset'),
            $this->option('max-rate') !== null ? (int) $this->option('max-rate') : null,
            $this->option('seed') !== null ? (int) $this->option('seed') : null,
        );
        AccountDirectory::forget();
        $account = $created['account'];

        $out = [
            'account_id' => $account->id,
            'run_id' => $account->current_run_id,
            'access_key_id' => $created['access_key_id'],
            // SigV4 signatures are accepted, not verified; any secret works. This one is just convenient.
            'secret_access_key' => $created['secret_access_key'],
            'truth_token' => $created['truth_token'],
            'topic_arn' => $account->topic_arn,
            'certificate_url' => $account->certificateUrl(),
            'subscription_status' => $account->subscription_status,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($out as $key => $value) {
                $this->line(str_pad($key, 22).(string) $value);
            }
            $this->warn('The secret and truth token are shown once.');
        }

        return self::SUCCESS;
    }
}
