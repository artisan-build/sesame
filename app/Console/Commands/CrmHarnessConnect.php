<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** LOCAL CRM-COMPATIBLE HARNESS. Stores the simulated account the harness sends as. */
class CrmHarnessConnect extends Command
{
    protected $signature = 'crm:harness:connect {credentials : Path to the JSON printed by sesame:account:create --json}';

    protected $description = '[local harness] Point the CRM harness at a Sesame simulated account.';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('The CRM harness does not run in production.');

            return self::FAILURE;
        }
        $credentials = json_decode((string) file_get_contents((string) $this->argument('credentials')), true, 8, JSON_THROW_ON_ERROR);
        foreach (['access_key_id', 'secret_access_key', 'truth_token', 'run_id'] as $key) {
            DB::table('harness_settings')->upsert(['key' => $key, 'value' => encrypt((string) $credentials[$key])], ['key'], ['value']);
        }
        $this->info('Harness connected to run '.$credentials['run_id'].'.');

        return self::SUCCESS;
    }
}
