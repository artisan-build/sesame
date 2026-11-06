<?php

namespace App\Sesame\Dataplane;

use App\Sesame\Sns\SesEvent;
use App\Sesame\Wal\WriteAheadLog;
use Illuminate\Support\Facades\DB;
use PDO;
use Pdo\Pgsql;
use RuntimeException;

/**
 * Moves accepted sends from the write-ahead log into Postgres with batched
 * COPY. The WAL checkpoint commits in the same transaction as the rows, so
 * every record is written exactly once across crashes and restarts.
 */
final class WalIngester
{
    public function __construct(private readonly WriteAheadLog $wal, private readonly int $batch = 2000) {}

    /**
     * @param  array<string, string>  $accountNumbers  account id => simulated AWS account number
     * @return list<array{id: string, account_id: string, due: int}> newly scheduled events
     */
    public function ingest(array $accountNumbers): array
    {
        DB::table('sesame_wal_checkpoint')->insertOrIgnore(['id' => 1, 'segment' => '', 'offset' => 0, 'updated_at' => now()]);
        $checkpoint = DB::table('sesame_wal_checkpoint')->where('id', 1)->first();
        $read = $this->wal->readFrom((string) $checkpoint->segment, (int) $checkpoint->offset, $this->batch);
        if ($read['records'] === [] && $read['checkpoint']['segment'] === $checkpoint->segment && $read['checkpoint']['offset'] === (int) $checkpoint->offset) {
            return [];
        }

        $region = (string) config('sesame.region');
        $messages = $events = $refusals = $scheduled = [];
        foreach ($read['records'] as $record) {
            if ($record['k'] !== 'm') {
                $refusals[] = [$record['a'], $record['r'], $record['k'], SesEvent::iso((int) $record['t'])];

                continue;
            }
            $messages[] = [
                $record['id'], $record['a'], $record['r'], SesEvent::iso((int) $record['t']), $record['src'],
                json_encode($record['dst']), $record['cs'], $record['tn'], json_encode((object) $record['tags']),
                json_encode($record['hdr']), json_encode($record['ch']), $record['sz'], json_encode($record['o']),
            ];
            $number = $accountNumbers[$record['a']] ?? '000000000000';
            foreach ($record['ev'] as $event) {
                $events[] = [
                    $event['id'], $event['sns'], $record['a'], $record['r'], $record['id'], $event['type'], $event['rcpt'],
                    $event['dup'] ?? null, SesEvent::iso((int) $event['due']), 'pending',
                    json_encode(SesEvent::payload($record, $event, $region, $number), JSON_UNESCAPED_SLASHES), 0, SesEvent::iso((int) $record['t']),
                ];
                $scheduled[] = ['id' => $event['id'], 'account_id' => $record['a'], 'due' => (int) $event['due']];
            }
        }

        DB::transaction(function () use ($checkpoint, $read, $messages, $events, $refusals): void {
            $locked = DB::table('sesame_wal_checkpoint')->where('id', 1)->lockForUpdate()->first();
            if ($locked->segment !== $checkpoint->segment || (int) $locked->offset !== (int) $checkpoint->offset) {
                throw new RuntimeException('Another data plane advanced the WAL checkpoint.');
            }
            $this->copy('sesame_messages', ['message_id', 'account_id', 'run_id', 'accepted_at', 'source', 'destination', 'configuration_set', 'tenant_name', 'tags', 'headers', 'common_headers', 'raw_bytes', 'outcomes'], $messages);
            $this->copy('sesame_events', ['id', 'sns_message_id', 'account_id', 'run_id', 'message_id', 'type', 'recipient', 'duplicate_of', 'due_at', 'status', 'payload', 'attempts', 'created_at'], $events);
            $this->copy('sesame_send_refusals', ['account_id', 'run_id', 'kind', 'at'], $refusals);
            DB::table('sesame_wal_checkpoint')->where('id', 1)->update([
                'segment' => $read['checkpoint']['segment'],
                'offset' => $read['checkpoint']['offset'],
                'updated_at' => now(),
            ]);
        });

        $this->wal->purgeBefore($read['checkpoint']['segment']);

        return $scheduled;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<mixed>>  $rows
     */
    private function copy(string $table, array $columns, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $lines = array_map(fn (array $row): string => implode("\t", array_map(self::field(...), $row)), $rows);
        /** @var PDO $pdo */
        $pdo = DB::connection()->getPdo();
        $ok = $pdo instanceof Pgsql
            ? $pdo->copyFromArray($table, $lines, "\t", '\\\\N', implode(',', $columns))
            : $pdo->pgsqlCopyFromArray($table, $lines, "\t", '\\\\N', implode(',', $columns));
        if (! $ok) {
            throw new RuntimeException("COPY into {$table} failed.");
        }
    }

    /** Postgres COPY text format escaping. */
    private static function field(mixed $value): string
    {
        if ($value === null) {
            return '\\N';
        }

        return strtr((string) $value, ['\\' => '\\\\', "\t" => '\\t', "\n" => '\\n', "\r" => '\\r']);
    }
}
