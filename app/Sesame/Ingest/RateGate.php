<?php

namespace App\Sesame\Ingest;

/**
 * Per-account max send rate in standard one-second windows, like SES. APCu
 * shares the counter across every FrankenPHP worker thread in the process;
 * without APCu (plain CLI) the counter is per process.
 */
final class RateGate
{
    /** @var array<string, int> */
    private static array $local = [];

    public static function admit(string $accountId, int $maxPerSecond): bool
    {
        if ($maxPerSecond <= 0) {
            return true;
        }
        $key = 'sesame:rate:'.$accountId.':'.time();

        if (function_exists('apcu_enabled') && apcu_enabled()) {
            apcu_add($key, 0, 3);
            $count = apcu_inc($key, 1, $success, 3);

            return $count !== false && $count <= $maxPerSecond;
        }

        if (count(self::$local) > 1000) {
            self::$local = [];
        }
        self::$local[$key] = (self::$local[$key] ?? 0) + 1;

        return self::$local[$key] <= $maxPerSecond;
    }

    public static function reset(): void
    {
        self::$local = [];
    }
}
