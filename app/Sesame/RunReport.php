<?php

namespace App\Sesame;

use App\Models\SesameRun;
use Illuminate\Support\Facades\DB;

/** Ground truth and simulator-side measurements for one run. */
final class RunReport
{
    /**
     * Messages with the outcome drawn and every event's delivery state, keyset-paginated by message ID.
     *
     * @return array{messages: list<array<string, mixed>>, next: ?string}
     */
    public static function truth(SesameRun $run, ?string $after, int $limit): array
    {
        $messages = DB::table('sesame_messages')->where('run_id', $run->id)
            ->when($after !== null, fn ($q) => $q->where('message_id', '>', $after))
            ->orderBy('message_id')->limit($limit)
            ->get(['message_id', 'accepted_at', 'destination', 'tags', 'outcomes']);
        $events = DB::table('sesame_events')->whereIn('message_id', $messages->pluck('message_id'))
            ->oldest('due_at')
            ->get(['message_id', 'sns_message_id', 'type', 'recipient', 'duplicate_of', 'status', 'attempts', 'last_status', 'latency_ms', 'due_at', 'delivered_at'])
            ->groupBy('message_id');

        $out = [];
        foreach ($messages as $message) {
            $out[] = [
                'message_id' => $message->message_id,
                'accepted_at' => $message->accepted_at,
                'destination' => json_decode($message->destination, true),
                'tags' => json_decode($message->tags, true),
                'outcomes' => json_decode($message->outcomes, true),
                'events' => ($events[$message->message_id] ?? collect())->map(fn ($e) => [
                    'sns_message_id' => $e->sns_message_id,
                    'type' => $e->type,
                    'recipient' => $e->recipient,
                    'duplicate' => $e->duplicate_of !== null,
                    'status' => $e->status,
                    'attempts' => (int) $e->attempts,
                    'last_status' => $e->last_status === null ? null : (int) $e->last_status,
                    'latency_ms' => $e->latency_ms === null ? null : (float) $e->latency_ms,
                    'due_at' => $e->due_at,
                    'delivered_at' => $e->delivered_at,
                ])->values()->all(),
            ];
        }

        return ['messages' => $out, 'next' => count($out) === $limit ? $messages->last()->message_id : null];
    }

    /** @return array<string, mixed> */
    public static function summary(SesameRun $run): array
    {
        $messages = DB::table('sesame_messages')->where('run_id', $run->id)
            ->selectRaw('count(*) as sent, min(accepted_at) as first_at, max(accepted_at) as last_at')->first();
        $perSecond = DB::table('sesame_messages')->where('run_id', $run->id)
            ->selectRaw("date_trunc('second', accepted_at) as s, count(*) as n")->groupByRaw('1')->orderByRaw('1')->pluck('n')->map(fn ($n) => (int) $n)->all();
        $outcomes = DB::table('sesame_messages')->where('run_id', $run->id)
            ->selectRaw("o.value->>'k' as kind, count(*) as n")->crossJoin(DB::raw('jsonb_each(outcomes) o'))
            ->groupByRaw('1')->pluck('n', 'kind')->map(fn ($n) => (int) $n)->all();
        $events = DB::table('sesame_events')->where('run_id', $run->id)
            ->selectRaw('type, status, count(*) as n')->groupBy('type', 'status')->get()
            ->map(fn ($r) => ['type' => $r->type, 'status' => $r->status, 'count' => (int) $r->n])->all();
        $latency = DB::selectOne(
            'SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY a.duration_ms) AS p50,
                    percentile_cont(0.95) WITHIN GROUP (ORDER BY a.duration_ms) AS p95,
                    percentile_cont(0.99) WITHIN GROUP (ORDER BY a.duration_ms) AS p99,
                    max(a.duration_ms) AS max, count(*) AS attempts,
                    count(*) FILTER (WHERE a.http_status BETWEEN 200 AND 299) AS ok,
                    count(*) FILTER (WHERE a.http_status >= 500 OR a.http_status = 429 OR a.http_status IS NULL) AS retryable_failures,
                    count(*) FILTER (WHERE a.http_status BETWEEN 300 AND 499 AND a.http_status <> 429) AS permanent_failures
             FROM sesame_delivery_attempts a JOIN sesame_events e ON e.id = a.event_id WHERE e.run_id = ?',
            [$run->id],
        );
        $refusals = DB::table('sesame_send_refusals')->where('run_id', $run->id)
            ->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind')->map(fn ($n) => (int) $n)->all();

        $statusCounts = ['pending' => 0, 'delivered' => 0, 'dropped' => 0];
        foreach ($events as $row) {
            $statusCounts[$row['status']] = ($statusCounts[$row['status']] ?? 0) + $row['count'];
        }

        return [
            'run' => ['id' => $run->id, 'seed' => $run->seed, 'preset' => $run->preset, 'label' => $run->label, 'started_at' => $run->started_at->toIso8601String()],
            'sends' => [
                'accepted' => (int) $messages->sent,
                'first_at' => $messages->first_at,
                'last_at' => $messages->last_at,
                'throttled' => $refusals['throttled'] ?? 0,
                'rejected' => $refusals['rejected'] ?? 0,
                'per_second' => $perSecond,
            ],
            'outcomes' => $outcomes,
            'events' => ['by_status' => $statusCounts, 'by_type' => $events],
            'webhook' => [
                'attempts' => (int) $latency->attempts,
                'ok' => (int) $latency->ok,
                'retryable_failures' => (int) $latency->retryable_failures,
                'permanent_failures' => (int) $latency->permanent_failures,
                'latency_ms' => ['p50' => self::round($latency->p50), 'p95' => self::round($latency->p95), 'p99' => self::round($latency->p99), 'max' => self::round($latency->max)],
            ],
        ];
    }

    private static function round(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }
}
