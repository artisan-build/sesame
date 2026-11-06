<?php

namespace App\CrmHarness;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * LOCAL CRM-COMPATIBLE HARNESS. Applies one SES event. Idempotent on the SNS
 * MessageId; correlates by mail.messageId, never by recipient address.
 */
final class ProcessSnsNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $snsMessageId)
    {
        $this->onQueue('crm-events');
    }

    public function handle(): void
    {
        $receipt = DB::table('harness_sns_receipts')->where('sns_message_id', $this->snsMessageId)->first();
        if ($receipt === null || $receipt->processed_at !== null) {
            return;
        }
        $envelope = json_decode($receipt->raw, true, 64, JSON_THROW_ON_ERROR);
        $event = json_decode($envelope['Message'], true, 64, JSON_THROW_ON_ERROR);
        $type = $event['eventType'] ?? null;
        $providerId = $event['mail']['messageId'] ?? null;

        DB::transaction(function () use ($receipt, $event, $type, $providerId): void {
            if (is_string($type) && is_string($providerId)) {
                DB::table('harness_message_events')->insertOrIgnore([
                    'sns_message_id' => $receipt->sns_message_id,
                    'provider_message_id' => $providerId,
                    'event_type' => $type,
                    'recipient' => $event['mail']['destination'][0] ?? null,
                    'bounce_type' => $event['bounce']['bounceType'] ?? null,
                    'bounce_subtype' => $event['bounce']['bounceSubType'] ?? null,
                    'tags' => json_encode($event['mail']['tags'] ?? (object) []),
                    'event_at' => $event[lcfirst($type)]['timestamp'] ?? $event['mail']['timestamp'] ?? null,
                    'received_at' => $receipt->received_at,
                    'processed_at' => Clock::now(),
                ]);
                Reconciler::apply($providerId);
            }
            DB::table('harness_sns_receipts')->where('id', $receipt->id)->update(['processed_at' => Clock::now()]);
        });
    }
}
