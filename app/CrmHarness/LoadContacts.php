<?php

namespace App\CrmHarness;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** LOCAL CRM-COMPATIBLE HARNESS. Synthetic contacts on the reserved load domain only. */
final class LoadContacts
{
    public const string DOMAIN = 'load.crm.test';

    /**
     * Idempotently seed $count contacts. Mostly plain addresses whose outcome
     * the lottery draws; every 200th forces a hard bounce and every 500th a
     * complaint, using SES-simulator-style local parts.
     */
    public static function seed(int $count): int
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The load-contact seeder refuses to run in production.');
        }
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $local = match (true) {
                $i % 500 === 0 => "complaint+{$i}",
                $i % 200 === 0 => "bounce+{$i}",
                default => "load-{$i}",
            };
            $rows[] = ['email' => $local.'@'.self::DOMAIN];
        }
        foreach (array_chunk($rows, 2000) as $chunk) {
            DB::table('harness_contacts')->insertOrIgnore($chunk);
        }

        return (int) DB::table('harness_contacts')->count();
    }
}
