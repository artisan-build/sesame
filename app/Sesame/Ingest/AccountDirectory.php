<?php

namespace App\Sesame\Ingest;

use Illuminate\Support\Facades\DB;

/**
 * The ingest path's view of simulated accounts.
 *
 * Read from Postgres at most once per `sesame.account_cache_ms` per instance:
 * an Octane worker keeps the row in memory, and PHP-FPM requests share it
 * through the instance's IngestBuffer file. So a preset or rate change
 * reaches every replica within that window, with no notifier, and a send
 * normally costs no Postgres round trip at all.
 */
final class AccountDirectory
{
    /** @var array<string, array{at: float, account: array<string, mixed>|null}> */
    private static array $cache = [];

    /** @return array<string, mixed>|null keyed fields: id, run_id, seed, rates, timing, max_send_rate, paused, source_account */
    public static function find(string $accessKeyId): ?array
    {
        $ttl = (float) config('sesame.account_cache_ms', 1000) / 1000;
        $hit = self::$cache[$accessKeyId] ?? null;
        if ($hit !== null && microtime(true) - $hit['at'] < $ttl) {
            return $hit['account'];
        }

        $ttlMs = (int) round($ttl * 1000);
        $cached = IngestBuffer::cachedAccount($accessKeyId, $ttlMs);
        if ($cached['hit']) {
            return (self::$cache[$accessKeyId] = ['at' => microtime(true), 'account' => $cached['account']])['account'];
        }

        $row = DB::table('sesame_accounts')
            ->leftJoin('sesame_runs', 'sesame_runs.id', '=', 'sesame_accounts.current_run_id')
            ->where('sesame_accounts.access_key_id', $accessKeyId)
            ->select('sesame_accounts.*', 'sesame_runs.seed as run_seed', 'sesame_runs.preset as run_preset')
            ->first();

        if (count(self::$cache) > 1000) {
            self::$cache = [];
        }

        $account = $row === null ? null : self::shape($row);
        IngestBuffer::cacheAccount($accessKeyId, $account);

        return (self::$cache[$accessKeyId] = ['at' => microtime(true), 'account' => $account])['account'];
    }

    /** Drop cached rows in this process, after a control-plane change. */
    public static function forget(): void
    {
        self::$cache = [];
        IngestBuffer::forgetAccounts();
    }

    /** @return array<string, mixed> */
    private static function shape(object $row): array
    {
        $presets = (array) config('sesame.presets');
        $preset = (string) ($row->run_preset ?? $row->preset);

        return [
            'id' => $row->id,
            'run_id' => $row->current_run_id,
            'seed' => (int) ($row->run_seed ?? 0),
            'rates' => array_merge($presets[$preset] ?? $presets['healthy'], json_decode((string) ($row->rate_overrides ?? 'null'), true) ?? []),
            'timing' => array_merge((array) config('sesame.timing'), json_decode((string) ($row->timing_overrides ?? 'null'), true) ?? []),
            'max_send_rate' => (int) $row->max_send_rate,
            'paused' => $preset === 'paused' && (bool) $row->sending_paused,
            'source_account' => $row->aws_account_number,
        ];
    }
}
