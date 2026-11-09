<?php

namespace App\Console\Commands;

use App\Models\SesameAccount;
use App\Sesame\Accounts;
use Illuminate\Console\Command;

class SesameAccountSubscribe extends Command
{
    protected $signature = 'sesame:account:subscribe
        {name : Existing simulated account name}
        {webhook : HTTPS staging webhook; this sends a SubscriptionConfirmation}
        {--staging-only : Confirm the webhook belongs to a staging app, never production}';

    protected $description = 'Explicitly register a staging SNS webhook after configuring its client.';

    public function handle(): int
    {
        if (! $this->option('staging-only')) {
            $this->error('No callback sent. Verify this is a staging webhook, then pass --staging-only.');

            return self::FAILURE;
        }

        $url = (string) $this->argument('webhook');
        $parts = parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            $this->error('Use an HTTPS staging webhook without URL credentials or a fragment.');

            return self::FAILURE;
        }
        $account = SesameAccount::where('name', $this->argument('name'))->first();
        if ($account === null) {
            $this->error('No such simulated account.');

            return self::FAILURE;
        }

        Accounts::subscribe($account, $url);
        $this->info('SubscriptionConfirmation queued. The staging webhook must verify it and follow its SubscribeURL before notifications are delivered.');

        return self::SUCCESS;
    }
}
