<?php

namespace App\Sesame\Dataplane;

use App\Sesame\Ingest\AccountDirectory;
use App\Sesame\Sns\SesEvent;
use App\Sesame\Sns\Signer;
use App\Sesame\Wal\WriteAheadLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Pdo\Pgsql;

/**
 * The long-running half of the data plane: WAL ingest with batched COPY, the
 * restart-safe timing wheel, SNS subscription confirmation, and signed
 * delivery through a fixed pool with SNS's HTTP retry rules. It reads
 * config and never writes it; changes arrive through LISTEN/NOTIFY.
 */
final class Dataplane
{
    private TimingWheel $wheel;

    private WalIngester $ingester;

    /** @var array<string, object> account id => account row */
    private array $accounts = [];

    /** @var array<string, array<string, true>> events held until the account's subscription is confirmed */
    private array $parked = [];

    /** @var array<string, array{attempt: int, first: ?string, account: string}> in-flight events */
    private array $inFlight = [];

    /** @var list<array<string, mixed>> */
    private array $attemptRows = [];

    /** @var array<string, array<string, mixed>> */
    private array $eventUpdates = [];

    private bool $listening = false;

    public function __construct(private readonly DeliveryPool $pool, ?WriteAheadLog $wal = null)
    {
        $this->wheel = new TimingWheel;
        $this->ingester = new WalIngester($wal ?? WriteAheadLog::fromConfig(), (int) config('sesame.dataplane.ingest_batch'));
    }

    public static function fromConfig(): self
    {
        return new self(new DeliveryPool((int) config('sesame.dataplane.delivery_concurrency'), (float) config('sesame.dataplane.delivery_timeout_seconds')));
    }

    /** Rebuild state after a start or restart: config, snapshot, and every pending event. */
    public function boot(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('LISTEN sesame_config');
        $this->listening = true;
        $this->reloadAccounts();

        DB::table('sesame_events')->where('status', 'pending')->orderBy('due_at')
            ->select(['id', 'account_id', 'due_at'])
            ->chunk(5000, function ($rows): void {
                foreach ($rows as $row) {
                    $this->wheel->add($row->id, $row->account_id, (int) Carbon::parse((string) $row->due_at)->getPreciseTimestamp(3));
                }
            });
    }

    /** One loop iteration. Returns true when it did any work. */
    public function tick(): bool
    {
        $worked = $this->pollConfig();

        $accountNumbers = array_map(fn (object $a): string => $a->aws_account_number, $this->accounts);
        foreach ($this->ingester->ingest($accountNumbers) as $event) {
            $this->wheel->add($event['id'], $event['account_id'], $event['due']);
            $worked = true;
        }

        $worked = $this->dispatchDue() || $worked;
        $worked = $this->collect($this->pool->tick()) || $worked;
        $this->flush();

        return $worked;
    }

    /** @param callable(): bool $shouldStop */
    public function run(callable $shouldStop): void
    {
        $this->boot();
        $tickUs = (int) config('sesame.dataplane.tick_ms') * 1000;
        while (! $shouldStop()) {
            if (! $this->tick()) {
                usleep($tickUs);
            }
        }
        $this->collect($this->pool->drain());
        $this->flush();
    }

    public function pending(): int
    {
        return $this->wheel->size() + array_sum(array_map('count', $this->parked)) + $this->pool->inFlight();
    }

    private function pollConfig(): bool
    {
        if (! $this->listening) {
            return false;
        }
        $pdo = DB::connection()->getPdo();
        $changed = false;
        while (($notify = ($pdo instanceof Pgsql ? $pdo->getNotify(PDO::FETCH_ASSOC, 0) : $pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, 0))) !== false) {
            $changed = true;
        }
        if ($changed) {
            $this->reloadAccounts();
        }

        return $changed;
    }

    public function reloadAccounts(): void
    {
        AccountDirectory::write();
        $this->accounts = DB::table('sesame_accounts')->get()->keyBy('id')->all();

        foreach ($this->accounts as $account) {
            if ($account->subscription_status === 'pending' && $account->webhook_url !== null) {
                $this->queueConfirmation($account);
            }
            if ($account->subscription_status === 'confirmed' && isset($this->parked[$account->id])) {
                $now = $this->nowMs();
                foreach (array_keys($this->parked[$account->id]) as $eventId) {
                    $this->wheel->add($eventId, $account->id, $now);
                }
                unset($this->parked[$account->id]);
            }
        }
    }

    /** Registering a webhook sends one SubscriptionConfirmation per subscription token. */
    private function queueConfirmation(object $account): void
    {
        $exists = DB::table('sesame_events')->where('account_id', $account->id)->where('type', 'SubscriptionConfirmation')
            ->whereRaw("payload->>'Token' = ?", [$account->subscription_token])->exists();
        if ($exists) {
            return;
        }
        $id = (string) Str::uuid7();
        $now = $this->nowMs();
        DB::table('sesame_events')->insert([
            'id' => $id, 'sns_message_id' => (string) Str::uuid(), 'account_id' => $account->id, 'type' => 'SubscriptionConfirmation',
            'due_at' => SesEvent::iso($now), 'status' => 'pending', 'created_at' => SesEvent::iso($now),
            'payload' => json_encode(['Token' => $account->subscription_token, 'TopicArn' => $account->topic_arn]),
        ]);
        $this->wheel->add($id, $account->id, $now);
    }

    private function dispatchDue(): bool
    {
        $capacity = $this->pool->capacity();
        if ($capacity === 0) {
            return false;
        }
        $due = $this->wheel->popDue($this->nowMs(), $capacity);
        if ($due === []) {
            return false;
        }
        $rows = DB::table('sesame_events')->whereIn('id', array_keys($due))->where('status', 'pending')->get()->keyBy('id');
        foreach ($due as $eventId => $accountId) {
            $event = $rows[$eventId] ?? null;
            $account = $this->accounts[$accountId] ?? null;
            if ($event === null || $account === null) {
                continue;
            }
            $confirmation = $event->type === 'SubscriptionConfirmation';
            if (! $confirmation && ($account->subscription_status !== 'confirmed' || $account->webhook_url === null)) {
                $this->parked[$accountId][$eventId] = true;

                continue;
            }
            [$headers, $body] = $this->envelope($event, $account);
            $this->inFlight[$eventId] = ['attempt' => (int) $event->attempts + 1, 'first' => $event->first_attempt_at, 'account' => $accountId];
            $this->pool->post($eventId, (string) $account->webhook_url, $headers, $body);
        }

        return true;
    }

    /** @return array{0: array<string, string>, 1: string} */
    private function envelope(object $event, object $account): array
    {
        $payload = json_decode((string) $event->payload, true);
        $common = [
            'MessageId' => $event->sns_message_id,
            'TopicArn' => $account->topic_arn,
            'Timestamp' => SesEvent::iso($this->nowMs()),
            'SigningCertURL' => rtrim((string) config('sesame.public_url'), '/').'/sns/'.$account->id.'/cert.pem',
        ];
        $headers = [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'User-Agent' => 'Amazon Simple Notification Service Agent',
            'x-amz-sns-message-id' => $event->sns_message_id,
            'x-amz-sns-topic-arn' => $account->topic_arn,
        ];

        if ($event->type === 'SubscriptionConfirmation') {
            $envelope = ['Type' => 'SubscriptionConfirmation'] + $common + [
                'Token' => $payload['Token'],
                'Message' => "You have chosen to subscribe to the topic {$account->topic_arn}.\nTo confirm the subscription, visit the SubscribeURL included in this message.",
                'SubscribeURL' => self::actionUrl($account, ['Action' => 'ConfirmSubscription', 'TopicArn' => $account->topic_arn, 'Token' => $payload['Token']]),
            ];
            $headers['x-amz-sns-message-type'] = 'SubscriptionConfirmation';
        } else {
            $envelope = ['Type' => 'Notification'] + $common + [
                'Message' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'UnsubscribeURL' => self::actionUrl($account, ['Action' => 'Unsubscribe', 'SubscriptionArn' => $account->subscription_arn]),
            ];
            $headers['x-amz-sns-message-type'] = 'Notification';
            $headers['x-amz-sns-subscription-arn'] = (string) $account->subscription_arn;
        }

        $signed = Signer::sign($envelope, decrypt($account->private_key, false));

        return [$headers, json_encode($signed, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)];
    }

    /** @param array<string, string|null> $query */
    private static function actionUrl(object $account, array $query): string
    {
        return rtrim((string) config('sesame.public_url'), '/').'/sns/'.$account->id.'/?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @param list<array{key: string, status: ?int, error: ?string, started_ms: float, duration_ms: float}> $results */
    private function collect(array $results): bool
    {
        $retries = (int) config('sesame.dataplane.retry.retries');
        $delayMs = (int) round((float) config('sesame.dataplane.retry.delay_seconds') * 1000);
        foreach ($results as $result) {
            $eventId = $result['key'];
            $attempt = $this->inFlight[$eventId]['attempt'] ?? 1;
            $first = $this->inFlight[$eventId]['first'] ?? null;
            $accountId = $this->inFlight[$eventId]['account'] ?? '';
            unset($this->inFlight[$eventId]);
            $status = $result['status'];
            $this->attemptRows[] = [
                'event_id' => $eventId, 'attempt' => $attempt, 'started_at' => SesEvent::iso((int) $result['started_ms']),
                'duration_ms' => round($result['duration_ms'], 3), 'http_status' => $status, 'error' => $result['error'],
            ];
            $update = ['attempts' => $attempt, 'last_status' => $status, 'last_error' => $result['error'], 'first_attempt_at' => $first ?? SesEvent::iso((int) $result['started_ms'])];

            if ($status !== null && $status >= 200 && $status < 300) {
                $update += ['status' => 'delivered', 'delivered_at' => SesEvent::iso((int) ($result['started_ms'] + $result['duration_ms'])), 'latency_ms' => round($result['duration_ms'], 3)];
            } elseif ($status === null || $status >= 500 || $status === 429) {
                // SNS retries only 5xx, 429 and transport failures.
                if ($attempt <= $retries) {
                    $next = $this->nowMs() + $delayMs;
                    $update += ['status' => 'pending', 'due_at' => SesEvent::iso($next)];
                    $this->wheel->add($eventId, $accountId, $next);
                } else {
                    $update += ['status' => 'dropped'];
                }
            } else {
                // Any other response is permanent: the event is gone, and Sesame reports it.
                $update += ['status' => 'dropped'];
            }
            $this->eventUpdates[$eventId] = $update;
        }

        return $results !== [];
    }

    /** Batched writes of attempt timings and event state. */
    private function flush(): void
    {
        if ($this->attemptRows !== []) {
            foreach (array_chunk($this->attemptRows, 1000) as $chunk) {
                DB::table('sesame_delivery_attempts')->insert($chunk);
            }
            $this->attemptRows = [];
        }
        if ($this->eventUpdates === []) {
            return;
        }
        foreach (array_chunk($this->eventUpdates, 500, true) as $chunk) {
            $values = [];
            $bindings = [];
            foreach ($chunk as $id => $update) {
                $values[] = '(?::uuid, ?, ?::smallint, ?::smallint, ?, ?::timestamptz, ?::timestamptz, ?::double precision, ?::timestamptz)';
                array_push($bindings, $id, $update['status'], $update['attempts'], $update['last_status'], $update['last_error'],
                    $update['first_attempt_at'], $update['delivered_at'] ?? null, $update['latency_ms'] ?? null, $update['due_at'] ?? null);
            }
            DB::update(
                'UPDATE sesame_events e SET status = v.status, attempts = v.attempts, last_status = v.last_status, last_error = v.last_error,
                    first_attempt_at = COALESCE(e.first_attempt_at, v.first_attempt_at), delivered_at = COALESCE(v.delivered_at, e.delivered_at),
                    latency_ms = COALESCE(v.latency_ms, e.latency_ms), due_at = COALESCE(v.due_at, e.due_at)
                 FROM (VALUES '.implode(',', $values).') AS v(id, status, attempts, last_status, last_error, first_attempt_at, delivered_at, latency_ms, due_at)
                 WHERE e.id = v.id',
                $bindings,
            );
        }
        $this->eventUpdates = [];
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
