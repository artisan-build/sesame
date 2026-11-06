<?php

namespace App\CrmHarness;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** LOCAL CRM-COMPATIBLE HARNESS settings, stored by crm:harness:connect. */
final class Settings
{
    public static function get(string $key): string
    {
        $value = DB::table('harness_settings')->where('key', $key)->value('value');
        if (! is_string($value)) {
            throw new RuntimeException("Harness setting [{$key}] is missing; run crm:harness:connect.");
        }

        return decrypt($value);
    }

    /** Apply the simulated account's keys to the crm-ses mailer. */
    public static function configureMailer(): void
    {
        config([
            'mail.mailers.crm-ses.key' => self::get('access_key_id'),
            'mail.mailers.crm-ses.secret' => self::get('secret_access_key'),
        ]);
    }
}
