<?php

namespace App\Console\Commands;

use App\CrmHarness\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * LOCAL CRM-COMPATIBLE HARNESS stand-in for the CRM reconcile job. Pages
 * through Sesame's ground truth for the run and compares it with CRM state:
 * message marked bounced, complaint recorded, contact suppressed.
 */
class CrmReconcile extends Command
{
    protected $signature = 'crm:reconcile
        {--run= : Harness load run label to compare}
        {--wait=0 : Seconds to wait for the simulator and the event queue to drain}
        {--json= : Write the full report to this path}';

    protected $description = '[local harness] Reconcile CRM state against Sesame ground truth and print CRM-side metrics.';

    public function handle(): int
    {
        $base = rtrim((string) config('services.ses.endpoint'), '/');
        $runId = Settings::get('run_id');
        $http = Http::withToken(Settings::get('truth_token'))->timeout(60)
            ->withOptions(['verify' => config('services.sns.ca_bundle') ?: true]);

        $deadline = time() + (int) $this->option('wait');
        do {
            $summary = $http->get("{$base}/sesame/v1/runs/{$runId}/summary")->throw()->json();
            $pendingSim = (int) $summary['events']['by_status']['pending'];
            $pendingCrm = DB::table('harness_sns_receipts')->whereNull('processed_at')->count()
                + DB::table('harness_outbound_messages')->where('state', 'queued')->count();
            $drained = $pendingSim === 0 && $pendingCrm === 0;
            if (! $drained && time() < $deadline) {
                $this->line("waiting: simulator pending={$pendingSim} crm pending={$pendingCrm}");
                Sleep::sleep(10);
            }
        } while (! $drained && time() < $deadline);

        $label = $this->option('run');
        $crm = DB::table('harness_outbound_messages')
            ->join('harness_contacts', 'harness_contacts.id', '=', 'harness_outbound_messages.contact_id')
            ->when($label, fn ($q) => $q->where('load_run', $label))
            ->whereNotNull('provider_message_id')
            ->get(['provider_message_id', 'delivery_state', 'delayed', 'complained', 'bounce_type', 'suppressed_at', 'suppression_reason'])
            ->keyBy('provider_message_id');
        $received = DB::table('harness_message_events')->pluck('sns_message_id')->flip();

        $mismatches = [];
        $seen = 0;
        $after = null;
        do {
            $page = $http->get("{$base}/sesame/v1/runs/{$runId}/truth", array_filter(['after' => $after, 'limit' => 2000]))->throw()->json();
            foreach ($page['messages'] as $truth) {
                $seen++;
                foreach ($this->compare($truth, $crm[$truth['message_id']] ?? null, $received) as $problem) {
                    $mismatches[] = ['message_id' => $truth['message_id'], 'problem' => $problem];
                }
                unset($crm[$truth['message_id']]);
            }
            $after = $page['next'];
        } while ($after !== null);
        // MessageIds Sesame returned but has no truth for. Sesame acknowledges a
        // send before it reaches Postgres, so a deploy or instance loss can
        // lose it: that is the simulator's loss and is reported apart from CRM
        // mismatches. Anything Sesame does not recognise is still a CRM finding.
        $lost = [];
        $undrained = 0;
        foreach ($crm->keys()->chunk(5000) as $chunk) {
            $classified = $http->post("{$base}/sesame/v1/runs/{$runId}/missing", ['message_ids' => $chunk->values()->all()])->throw()->json('message_ids');
            foreach ($chunk as $orphan) {
                $status = $classified[$orphan]['status'] ?? 'unknown';
                match ($status) {
                    'lost' => $lost[] = ['message_id' => $orphan] + $classified[$orphan],
                    'undrained' => $undrained++,
                    default => $mismatches[] = ['message_id' => $orphan, 'problem' => 'CRM has a MessageId the simulator never accepted'],
                };
            }
        }
        $drained = $drained && $undrained === 0;

        $report = [
            'drained' => $drained,
            'simulator' => $summary,
            'crm' => $this->crmMetrics($label),
            'reconciliation' => ['messages_compared' => $seen, 'mismatches' => count($mismatches), 'examples' => array_slice($mismatches, 0, 20)],
            // Not CRM findings: sends Sesame acknowledged and then lost with an ingest buffer.
            'simulator_losses' => ['lost_in_ingest_buffer' => count($lost), 'undrained' => $undrained, 'examples' => array_slice($lost, 0, 20)],
        ];
        if ($this->option('json')) {
            file_put_contents((string) $this->option('json'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $this->line((string) json_encode(['drained' => $drained, 'crm' => $report['crm'], 'reconciliation' => $report['reconciliation'], 'simulator_losses' => $report['simulator_losses']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($lost !== []) {
            $this->warn(count($lost).' accepted send(s) were lost in a Sesame ingest buffer (deploy or instance loss during the run). Not a CRM finding; the run is incomplete.');
        }

        // 1: the CRM disagrees with the truth. 2: only the simulator lost sends.
        return match (true) {
            ! $drained || $mismatches !== [] => self::FAILURE,
            $lost !== [] => 2,
            default => self::SUCCESS,
        };
    }

    /**
     * @param  array<string, mixed>  $truth
     * @param  Collection<string, int>  $received
     * @return list<string>
     */
    private function compare(array $truth, ?object $crm, $received): array
    {
        if ($crm === null) {
            return ['simulator accepted a message the CRM has no MessageId for'];
        }
        $problems = [];
        foreach ($truth['events'] as $event) {
            if ($event['status'] === 'dropped') {
                $problems[] = "{$event['type']} event dropped after HTTP {$event['last_status']}";
            } elseif ($event['status'] === 'pending') {
                $problems[] = "{$event['type']} event still pending";
            } elseif (! isset($received[$event['sns_message_id']])) {
                $problems[] = "{$event['type']} event delivered but not recorded by the CRM";
            }
        }
        $outcome = array_values($truth['outcomes'])[0]['k'];
        $types = array_column($truth['events'], 'type');
        $expected = match ($outcome) {
            'delivered' => 'delivered',
            'hard_bounce' => 'bounced',
            'soft_bounce' => 'soft_bounced',
            'complaint' => 'complained',
            'delay' => in_array('Bounce', $types, true) ? 'bounced' : 'delivered',
            default => 'unknown',
        };
        if ($crm->delivery_state !== $expected) {
            $problems[] = "state {$crm->delivery_state}, expected {$expected}";
        }
        $shouldSuppress = in_array($expected, ['bounced', 'complained'], true);
        if ($shouldSuppress && $crm->suppressed_at === null) {
            $problems[] = 'contact not suppressed';
        }
        if (! $shouldSuppress && $crm->suppressed_at !== null) {
            $problems[] = "contact suppressed ({$crm->suppression_reason}) without a suppressing outcome";
        }
        if ($outcome === 'delay' && ! $crm->delayed) {
            $problems[] = 'DeliveryDelay not recorded';
        }

        return $problems;
    }

    /** @return array<string, mixed> */
    private function crmMetrics(?string $label): array
    {
        $where = $label ? 'WHERE load_run = ?' : '';
        $bind = $label ? [$label] : [];
        $send = DB::selectOne("SELECT count(*) AS total,
                count(*) FILTER (WHERE state = 'accepted') AS accepted,
                count(*) FILTER (WHERE state = 'failed') AS failed,
                count(*) FILTER (WHERE state = 'queued') AS queued,
                count(*) FILTER (WHERE state = 'skipped_suppressed') AS skipped_suppressed,
                sum(throttled) AS throttled,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY extract(epoch FROM accepted_at - dispatched_at) * 1000) AS send_p50,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY extract(epoch FROM accepted_at - dispatched_at) * 1000) AS send_p95,
                max(extract(epoch FROM started_at - dispatched_at)) AS max_queue_lag_s,
                min(accepted_at) AS first_accept, max(accepted_at) AS last_accept
            FROM harness_outbound_messages {$where}", $bind);
        $perSecond = collect(DB::select("SELECT count(*) AS n FROM harness_outbound_messages {$where}".($label ? ' AND' : ' WHERE')
            ." accepted_at IS NOT NULL GROUP BY date_trunc('second', accepted_at) ORDER BY date_trunc('second', accepted_at)", $bind))->pluck('n')->map(fn ($n) => (int) $n);
        $events = DB::selectOne('SELECT count(*) AS n,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY extract(epoch FROM processed_at - received_at) * 1000) AS p50,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY extract(epoch FROM processed_at - received_at) * 1000) AS p95
            FROM harness_message_events');
        $plateau = $perSecond->slice(5, max(0, $perSecond->count() - 10)); // trim ramp-up and drain seconds

        return [
            'sends' => [
                'intended' => (int) $send->total, 'accepted' => (int) $send->accepted, 'failed' => (int) $send->failed,
                'still_queued' => (int) $send->queued, 'skipped_suppressed' => (int) $send->skipped_suppressed, 'throttled_retries' => (int) $send->throttled,
                'first_accept' => $send->first_accept, 'last_accept' => $send->last_accept,
                'accepted_per_second_on_plateau' => ['mean' => round((float) $plateau->avg(), 2), 'min' => $plateau->min(), 'max' => $plateau->max(), 'seconds' => $plateau->count()],
                'send_latency_ms' => ['p50' => round((float) $send->send_p50, 1), 'p95' => round((float) $send->send_p95, 1)],
                'max_queue_lag_seconds' => round((float) $send->max_queue_lag_s, 2),
            ],
            'events' => ['recorded' => (int) $events->n, 'event_lag_ms' => ['p50' => round((float) $events->p50, 1), 'p95' => round((float) $events->p95, 1)]],
            'suppressed_contacts' => DB::table('harness_contacts')->whereNotNull('suppressed_at')->count(),
        ];
    }
}
