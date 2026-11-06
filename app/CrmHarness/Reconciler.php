<?php

namespace App\CrmHarness;

use Illuminate\Support\Facades\DB;

/**
 * LOCAL CRM-COMPATIBLE HARNESS. Recomputes a message's state from every event
 * received for it, so arrival order and duplicates cannot corrupt it: a
 * Delivery before its Send, or a second Bounce, lands on the same answer.
 * Suppression is only ever added, never cleared by a later event.
 */
final class Reconciler
{
    public static function apply(string $providerMessageId): void
    {
        $message = DB::table('harness_outbound_messages')->where('provider_message_id', $providerMessageId)->lockForUpdate()->first();
        if ($message === null) {
            return; // the send job has not stored the MessageId yet; it calls apply() when it does
        }
        $events = DB::table('harness_message_events')->where('provider_message_id', $providerMessageId)->get();

        $types = $events->pluck('event_type')->all();
        $permanent = $events->first(fn ($e) => $e->event_type === 'Bounce' && $e->bounce_type === 'Permanent');
        $bounce = $permanent ?? $events->first(fn ($e) => $e->event_type === 'Bounce');
        $complaint = $events->first(fn ($e) => $e->event_type === 'Complaint');
        $delivery = $events->filter(fn ($e) => $e->event_type === 'Delivery')->sortBy('event_at')->first();

        $state = match (true) {
            $complaint !== null => 'complained',
            $permanent !== null => 'bounced',
            $bounce !== null => 'soft_bounced',
            $delivery !== null => 'delivered',
            in_array('DeliveryDelay', $types, true) => 'delayed',
            in_array('Send', $types, true) => 'sent',
            default => 'unknown',
        };

        DB::table('harness_outbound_messages')->where('id', $message->id)->update([
            'delivery_state' => $state,
            'delivered_at' => $delivery?->event_at,
            'bounce_type' => $bounce?->bounce_type,
            'bounce_subtype' => $bounce?->bounce_subtype,
            'delayed' => in_array('DeliveryDelay', $types, true),
            'complained' => $complaint !== null,
        ]);

        $suppressing = $complaint ?? $permanent;
        if ($suppressing !== null) {
            DB::table('harness_contacts')->where('id', $message->contact_id)->whereNull('suppressed_at')->update([
                'suppressed_at' => now(),
                'suppression_reason' => $complaint !== null ? 'complaint' : 'hard_bounce',
                'suppression_event' => $suppressing->sns_message_id,
            ]);
        }
    }
}
