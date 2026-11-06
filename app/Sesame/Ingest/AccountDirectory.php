<?php

namespace App\Sesame\Ingest;

use Illuminate\Support\Facades\DB;

/**
 * The ingest path's read-only view of simulated accounts. It is a snapshot
 * file the data plane rewrites on Postgres NOTIFY, so a SendEmail request
 * costs a stat() rather than a database round trip.
 */
final class AccountDirectory
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $accounts = null;

    /** Snapshot identity: path, inode and mtime. Every write is a rename, so the inode changes even within one second. */
    private static ?string $version = null;

    /** @return array<string, mixed>|null keyed fields: id, run_id, seed, rates, timing, max_send_rate, paused */
    public static function find(string $accessKeyId): ?array
    {
        $path = (string) config('sesame.wal.snapshot');
        clearstatcache(true, $path);
        $stat = @stat($path);
        if ($stat === false) {
            self::write();
            clearstatcache(true, $path);
            $stat = @stat($path);
        }
        $version = $path.':'.($stat === false ? '-' : $stat['ino'].':'.$stat['mtime']);
        if (self::$accounts === null || $version !== self::$version) {
            $decoded = json_decode((string) @file_get_contents($path), true);
            self::$accounts = is_array($decoded) ? $decoded['accounts'] ?? [] : [];
            self::$version = $version;
        }

        return self::$accounts[$accessKeyId] ?? null;
    }

    /** Rebuild the snapshot from Postgres and replace it atomically. */
    public static function write(): void
    {
        $presets = (array) config('sesame.presets');
        $accounts = [];
        $rows = DB::table('sesame_accounts')
            ->leftJoin('sesame_runs', 'sesame_runs.id', '=', 'sesame_accounts.current_run_id')
            ->select('sesame_accounts.*', 'sesame_runs.seed as run_seed', 'sesame_runs.preset as run_preset')
            ->get();
        foreach ($rows as $row) {
            $preset = (string) ($row->run_preset ?? $row->preset);
            $accounts[$row->access_key_id] = [
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

        $path = (string) config('sesame.wal.snapshot');
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        $tmp = $path.'.'.getmypid().'.'.bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode(['written_at' => microtime(true), 'accounts' => $accounts], JSON_THROW_ON_ERROR));
        rename($tmp, $path);
        self::$accounts = null;
    }
}
