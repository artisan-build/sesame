<?php

namespace App\Sesame\Ingest;

use App\Sesame\Outcome;
use Illuminate\Support\Str;

/**
 * Turns drawn outcomes into the SES events a message will publish, each with
 * its own SNS MessageId and due time. Planned at ingest and stored with the
 * send, so a repeated drain writes exactly the same events.
 */
final class EventPlan
{
    /**
     * @param  array<string, Outcome>  $outcomes  keyed by recipient
     * @return list<array{id: string, sns: string, type: string, rcpt: ?string, due: int, bt?: string, sub?: ?string}>
     */
    public static function for(int $acceptedMs, array $outcomes): array
    {
        $events = [];
        $sendDue = $acceptedMs;
        foreach ($outcomes as $recipient => $outcome) {
            $final = null;
            switch ($outcome->kind) {
                case Outcome::DELIVERED:
                    $final = self::event('Delivery', $recipient, $acceptedMs + $outcome->finalDelayMs);
                    break;
                case Outcome::HARD_BOUNCE:
                case Outcome::SOFT_BOUNCE:
                    $final = self::event('Bounce', $recipient, $acceptedMs + $outcome->finalDelayMs, [
                        'bt' => $outcome->kind === Outcome::HARD_BOUNCE ? 'Permanent' : 'Transient',
                        'sub' => $outcome->subType,
                    ]);
                    break;
                case Outcome::COMPLAINT:
                    // A complaint follows a successful delivery.
                    $events[] = self::event('Delivery', $recipient, $acceptedMs + $outcome->finalDelayMs);
                    $final = self::event('Complaint', $recipient, $acceptedMs + $outcome->complaintDelayMs, ['sub' => $outcome->subType]);
                    break;
                case Outcome::DELAY:
                    $events[] = self::event('DeliveryDelay', $recipient, $acceptedMs + $outcome->delayNoticeMs, ['sub' => $outcome->subType]);
                    $final = $outcome->delayThenBounce
                        ? self::event('Bounce', $recipient, $acceptedMs + $outcome->finalDelayMs, ['bt' => 'Permanent', 'sub' => 'General'])
                        : self::event('Delivery', $recipient, $acceptedMs + $outcome->finalDelayMs);
                    break;
            }
            if ($final === null) {
                continue;
            }
            $events[] = $final;
            if ($outcome->duplicate) {
                // SNS is at-least-once: same SNS MessageId, delivered again shortly after.
                $events[] = ['id' => (string) Str::uuid7(), 'due' => $final['due'] + 2000, 'dup' => $final['id']] + $final;
            }
            if ($outcome->outOfOrder) {
                $sendDue = max($sendDue, $final['due'] + 500);
            }
        }

        array_unshift($events, self::event('Send', null, $sendDue));

        return $events;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{id: string, sns: string, type: string, rcpt: ?string, due: int}
     */
    private static function event(string $type, ?string $recipient, int $due, array $extra = []): array
    {
        $id = (string) Str::uuid7();

        return ['id' => $id, 'sns' => (string) Str::uuid(), 'type' => $type, 'rcpt' => $recipient, 'due' => $due] + $extra;
    }
}
