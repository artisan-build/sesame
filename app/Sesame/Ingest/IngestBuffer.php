<?php

namespace App\Sesame\Ingest;

use App\Sesame\Delivery\Wakeups;
use App\Sesame\Sns\SesEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/**
 * The ingest buffer: a SQLite file in the instance's temporary directory.
 *
 * SendEmail commits an accepted send here, in one local transaction with its
 * share of the per-second send rate, and returns the MessageId. The send then
 * reaches Postgres when this instance drains the buffer after the response
 * (see Housekeeping): that request's drain, or whichever comes next on this
 * instance, takes every row it can. There is no scheduler; an instance that
 * gets no requests has nothing new to drain.
 *
 * ── THIS IS NOT DURABLE, BY DECISION ────────────────────────────────────────
 *
 * Laravel Cloud replaces an instance's filesystem on every deploy and reboot.
 * A send acknowledged but not yet drained when that happens, or when the
 * instance is lost unexpectedly, is gone. Draining after every request keeps
 * that window to the sends of the last moments, not to zero. Ed accepted the
 * tradeoff on 2026-11-06; Scalpels turns off push-to-deploy for Sesame so a
 * deploy is never accidental. A lost send is detectable afterwards: its
 * MessageId names this buffer and its sequence number, and Postgres records
 * how far each buffer was drained (see lossFor()).
 *
 * A crash of one PHP process does not lose committed rows (WAL journal). A
 * wake from hibernation thaws the same container and keeps the file (the
 * scalpels.app lab), and the buffer is normally empty by then anyway: each
 * drain runs moments after a request, and hibernation follows minutes of none.
 */
final class IngestBuffer
{
    /** @var array<string, PDO> one connection per buffer path, kept across Octane requests */
    private static array $connections = [];

    /** @var array<string, string> buffer id per path */
    private static array $ids = [];

    public static function path(): string
    {
        return (string) config('sesame.buffer.path');
    }

    /** The buffer's identity. A new file, as after a deploy, is a new buffer. */
    public static function id(): string
    {
        self::pdo();

        return self::$ids[self::path()];
    }

    public static function messageId(string $bufferId, int $acceptedMs, int $seq): string
    {
        return sprintf('0100%012x-%s-%06x', $acceptedMs, $bufferId, $seq);
    }

    /** @return array{buffer: string, seq: int}|null */
    public static function parseMessageId(string $messageId): ?array
    {
        if (preg_match('/\A0100[0-9a-f]{12}-([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})-([0-9a-f]{6,})\z/', $messageId, $m) !== 1) {
            return null;
        }

        return ['buffer' => $m[1], 'seq' => (int) hexdec($m[2])];
    }

    /**
     * Count the send against the account's per-second rate and, if admitted,
     * commit it to the buffer. Returns the MessageId, or null when throttled.
     *
     * @param  array<string, mixed>  $account  as returned by AccountDirectory::find()
     * @param  array<string, mixed>  $send  a, r, t, src, dst, cs, tn, tags, hdr, ch, sz, o, ev
     */
    public static function accept(array $account, array $send): ?string
    {
        $pdo = self::pdo();
        $second = intdiv((int) $send['t'], 1000);
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $max = (int) $account['max_send_rate'];
            $rate = $pdo->prepare('INSERT INTO rate (account_id, second, sends) VALUES (?, ?, 1)
                ON CONFLICT (account_id, second) DO UPDATE SET sends = sends + 1 RETURNING sends');
            $rate->execute([$account['id'], $second]);
            $sends = (int) $rate->fetchColumn();
            $rate->closeCursor();
            if ($max > 0 && $sends > $max) {
                $pdo->prepare('INSERT INTO refusals (id, account_id, run_id, kind, at_ms) VALUES (?, ?, ?, ?, ?)')
                    ->execute([(string) Str::uuid7(), $account['id'], $account['run_id'], 'throttled', (int) $send['t']]);
                $pdo->exec('COMMIT');

                return null;
            }
            $pdo->prepare('INSERT INTO sends (body) VALUES (?)')
                ->execute([json_encode($send + ['n' => $account['source_account']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            $seq = (int) $pdo->lastInsertId();
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }

        return self::messageId(self::id(), (int) $send['t'], $seq);
    }

    /** @param array<string, mixed> $account */
    public static function refusal(array $account, string $kind, int $atMs): void
    {
        self::pdo()->prepare('INSERT INTO refusals (id, account_id, run_id, kind, at_ms) VALUES (?, ?, ?, ?, ?)')
            ->execute([(string) Str::uuid7(), $account['id'], $account['run_id'], $kind, $atMs]);
    }

    /**
     * An account row cached on this instance, so a PHP-FPM request (which
     * keeps no memory between requests) need not ask Postgres for it.
     *
     * @return array{hit: bool, account: array<string, mixed>|null}
     */
    public static function cachedAccount(string $accessKeyId, int $maxAgeMs): array
    {
        $statement = self::pdo()->prepare('SELECT body FROM accounts WHERE access_key_id = ? AND fetched_ms >= ?');
        $statement->execute([$accessKeyId, (int) floor(microtime(true) * 1000) - $maxAgeMs]);
        $body = $statement->fetchColumn();

        return $body === false ? ['hit' => false, 'account' => null] : ['hit' => true, 'account' => json_decode((string) $body, true)];
    }

    /** @param array<string, mixed>|null $account */
    public static function cacheAccount(string $accessKeyId, ?array $account): void
    {
        self::pdo()->prepare('INSERT OR REPLACE INTO accounts (access_key_id, body, fetched_ms) VALUES (?, ?, ?)')
            ->execute([$accessKeyId, json_encode($account), (int) floor(microtime(true) * 1000)]);
    }

    public static function forgetAccounts(): void
    {
        self::pdo()->exec('DELETE FROM accounts');
    }

    /** Sends and refusals still waiting to reach Postgres. */
    public static function depth(): int
    {
        $pdo = self::pdo();

        return (int) $pdo->query('SELECT (SELECT count(*) FROM sends) + (SELECT count(*) FROM refusals)')->fetchColumn();
    }

    /**
     * Move what the buffer holds to Postgres, as much as the budget allows:
     * every row any request left, not only the caller's own. Runs after the
     * response (Housekeeping), so it holds a worker, never a client.
     *
     * Ownership: one drain per buffer at a time, by an exclusive file lock.
     * A caller that finds the lock taken returns at once, because the holder
     * looks again after letting go: a row committed before a failed attempt
     * to take the lock is seen by the holder's last look, so a send is never
     * stranded by losing that race.
     *
     * Bounded: batches of `sesame.buffer.drain_batch` rows until the buffer is
     * empty or `sesame.buffer.drain_seconds` pass. Whatever is left waits for
     * the next request's drain.
     *
     * Exactly once across crashes: rows are copied with ON CONFLICT DO
     * NOTHING on their stable IDs, their wakeups are queued, and only then are
     * they deleted from SQLite, so a drain interrupted anywhere repeats
     * harmlessly.
     *
     * @return int sends moved
     */
    public static function drain(?int $batch = null, ?float $maxSeconds = null): int
    {
        $pdo = self::pdo();
        $batch = max(1, $batch ?? (int) config('sesame.buffer.drain_batch'));
        $deadline = microtime(true) + ($maxSeconds ?? (float) config('sesame.buffer.drain_seconds'));
        $moved = 0;
        do {
            $lock = fopen(self::path().'.drain.lock', 'c');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                return $moved;
            }
            try {
                do {
                    $sends = $pdo->query('SELECT seq, body FROM sends ORDER BY seq LIMIT '.$batch)->fetchAll(PDO::FETCH_ASSOC);
                    $refusals = $pdo->query('SELECT id, account_id, run_id, kind, at_ms FROM refusals ORDER BY at_ms LIMIT '.$batch)->fetchAll(PDO::FETCH_ASSOC);
                    if ($sends === [] && $refusals === []) {
                        break;
                    }
                    Wakeups::at(self::copy($sends, $refusals));
                    if ($sends !== []) {
                        $pdo->prepare('DELETE FROM sends WHERE seq <= ?')->execute([(int) end($sends)['seq']]);
                    }
                    if ($refusals !== []) {
                        $pdo->prepare('DELETE FROM refusals WHERE id IN ('.implode(',', array_fill(0, count($refusals), '?')).')')
                            ->execute(array_column($refusals, 'id'));
                    }
                    $moved += count($sends);
                } while (microtime(true) < $deadline);
                $pdo->prepare('DELETE FROM rate WHERE second < ?')->execute([time() - 5]);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } while (microtime(true) < $deadline && self::depth() > 0);

        return $moved;
    }

    /**
     * Claim this instance's turn at the periodic housekeeping (heartbeat,
     * overdue repair, pruning): true at most once per $seconds per buffer,
     * whichever of its workers asks first.
     */
    public static function claimHousekeeping(int $seconds): bool
    {
        $pdo = self::pdo();
        $now = time();
        $pdo->exec("INSERT OR IGNORE INTO meta (k, v) VALUES ('housekeeping_at', '0')");
        $claim = $pdo->prepare("UPDATE meta SET v = ? WHERE k = 'housekeeping_at' AND CAST(v AS INTEGER) <= ?");
        $claim->execute([(string) $now, $now - $seconds]);

        return $claim->rowCount() === 1;
    }

    /**
     * How long a buffer may go unseen before it counts as gone. A live buffer
     * is seen at every drain and, while its instance gets requests, at least
     * every housekeeping interval. With no scheduler, an idle instance is not
     * seen at all; a row it failed to drain counts as lost until a request
     * reaches that instance and drains it.
     */
    public const int GONE_AFTER_SECONDS = 30;

    /**
     * Classify a MessageId that Sesame returned but Postgres does not hold.
     *
     * - lost: its buffer was replaced (deploy, reboot, instance loss) before
     *   draining it. A simulator loss, not a finding about the client.
     * - undrained: its buffer is alive and has not drained it yet; ask again.
     * - unknown: not a MessageId of this Sesame, or its buffer never reached
     *   Postgres at all (the instance died within its first second).
     *
     * @return array{status: 'lost'|'undrained'|'unknown', buffer?: string, seq?: int, drained_through?: int, host?: string, last_seen_at?: string}
     */
    public static function lossFor(string $messageId): array
    {
        $parsed = self::parseMessageId($messageId);
        $buffer = $parsed === null ? null : DB::table('sesame_buffers')->where('id', $parsed['buffer'])->first();
        if ($parsed === null || $buffer === null) {
            return ['status' => 'unknown'];
        }
        $gone = DB::selectOne('SELECT ?::timestamptz < now() - make_interval(secs => ?) AS gone', [$buffer->last_seen_at, self::GONE_AFTER_SECONDS])->gone;

        return [
            'status' => $parsed['seq'] <= (int) $buffer->drained_through_seq ? 'unknown' : ($gone ? 'lost' : 'undrained'),
            'buffer' => $parsed['buffer'], 'seq' => $parsed['seq'], 'drained_through' => (int) $buffer->drained_through_seq,
            'host' => (string) $buffer->host, 'last_seen_at' => (string) $buffer->last_seen_at,
        ];
    }

    /** Tell Postgres this buffer exists and is alive, so its losses are identifiable even if it never drains a send. */
    public static function register(): void
    {
        DB::statement(
            'INSERT INTO sesame_buffers (id, host, opened_at, last_seen_at) VALUES (?, ?, now(), now())
             ON CONFLICT (id) DO UPDATE SET last_seen_at = now()',
            [self::id(), (string) gethostname()],
        );
    }

    /**
     * @param  list<array{seq: int|string, body: string}>  $sends
     * @param  list<array<string, mixed>>  $refusals
     * @return list<int> the events' due times, epoch milliseconds
     */
    private static function copy(array $sends, array $refusals): array
    {
        $bufferId = self::id();
        $region = (string) config('sesame.region');
        $messages = $events = $due = [];
        foreach ($sends as $row) {
            $send = json_decode($row['body'], true, 64, JSON_THROW_ON_ERROR);
            $send['id'] = self::messageId($bufferId, (int) $send['t'], (int) $row['seq']);
            $accepted = SesEvent::iso((int) $send['t']);
            $messages[] = [
                'message_id' => $send['id'], 'account_id' => $send['a'], 'run_id' => $send['r'], 'accepted_at' => $accepted,
                'source' => $send['src'], 'destination' => json_encode($send['dst']), 'configuration_set' => $send['cs'],
                'tenant_name' => $send['tn'], 'tags' => json_encode((object) $send['tags']), 'headers' => json_encode($send['hdr']),
                'common_headers' => json_encode($send['ch']), 'raw_bytes' => $send['sz'], 'outcomes' => json_encode($send['o']),
            ];
            foreach ($send['ev'] as $event) {
                $due[] = (int) $event['due'];
                $events[] = [
                    'id' => $event['id'], 'sns_message_id' => $event['sns'], 'account_id' => $send['a'], 'run_id' => $send['r'],
                    'message_id' => $send['id'], 'type' => $event['type'], 'recipient' => $event['rcpt'], 'duplicate_of' => $event['dup'] ?? null,
                    'due_at' => SesEvent::iso((int) $event['due']), 'status' => 'pending', 'attempts' => 0, 'created_at' => $accepted,
                    'payload' => json_encode(SesEvent::payload($send, $event, $region, (string) $send['n']), JSON_UNESCAPED_SLASHES),
                ];
            }
        }

        DB::transaction(function () use ($bufferId, $sends, $messages, $events, $refusals): void {
            foreach (array_chunk($messages, 200) as $chunk) {
                DB::table('sesame_messages')->insertOrIgnore($chunk);
            }
            foreach (array_chunk($events, 500) as $chunk) {
                DB::table('sesame_events')->insertOrIgnore($chunk);
            }
            foreach (array_chunk($refusals, 500) as $chunk) {
                DB::table('sesame_send_refusals')->insertOrIgnore(array_map(fn (array $r): array => [
                    'id' => $r['id'], 'account_id' => $r['account_id'], 'run_id' => $r['run_id'], 'kind' => $r['kind'], 'at' => SesEvent::iso((int) $r['at_ms']),
                ], $chunk));
            }
            $through = $sends === [] ? 0 : (int) end($sends)['seq'];
            DB::statement(
                'INSERT INTO sesame_buffers (id, host, opened_at, last_seen_at, drained_through_seq, drained_sends)
                 VALUES (?, ?, now(), now(), ?, ?)
                 ON CONFLICT (id) DO UPDATE SET last_seen_at = now(),
                    drained_through_seq = GREATEST(sesame_buffers.drained_through_seq, EXCLUDED.drained_through_seq),
                    drained_sends = sesame_buffers.drained_sends + EXCLUDED.drained_sends',
                [$bufferId, (string) gethostname(), $through, count($sends)],
            );
        });

        return $due;
    }

    private static function pdo(): PDO
    {
        $path = self::path();
        if (isset(self::$connections[$path]) && is_file($path)) {
            return self::$connections[$path];
        }
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        // A process crash keeps committed rows; only losing the instance loses
        // them, and then the whole file is gone anyway, so an fsync per
        // commit would buy nothing.
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS sends (seq INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS refusals (id TEXT PRIMARY KEY, account_id TEXT NOT NULL, run_id TEXT, kind TEXT NOT NULL, at_ms INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS accounts (access_key_id TEXT PRIMARY KEY, body TEXT, fetched_ms INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS rate (account_id TEXT NOT NULL, second INTEGER NOT NULL, sends INTEGER NOT NULL, PRIMARY KEY (account_id, second))');
        $created = $pdo->prepare('INSERT OR IGNORE INTO meta (k, v) VALUES (?, ?)');
        $created->execute(['buffer_id', (string) Str::uuid()]);
        self::$ids[$path] = (string) $pdo->query("SELECT v FROM meta WHERE k = 'buffer_id'")->fetchColumn();
        self::$connections[$path] = $pdo;
        if ($created->rowCount() === 1) {
            // A new file: register it now rather than at its first drain. If
            // this process dies first, the next request's housekeeping does.
            self::register();
        }

        return $pdo;
    }

    /** Forget open connections, so the next call reopens the file (tests, or after the file is replaced). */
    public static function reset(): void
    {
        self::$connections = [];
        self::$ids = [];
    }
}
