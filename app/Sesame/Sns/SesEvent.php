<?php

namespace App\Sesame\Sns;

/**
 * Builds SES event-publishing payloads (eventType, via a configuration set),
 * matching SES's documented event contents.
 */
final class SesEvent
{
    /**
     * @param  array<string, mixed>  $message  an ingest-buffer send record
     * @param  array<string, mixed>  $event  one planned event from it
     * @return array<string, mixed>
     */
    public static function payload(array $message, array $event, string $region, string $accountNumber): array
    {
        $source = self::sourceAddress($message);
        $tags = [];
        if (is_string($message['cs'] ?? null)) {
            $tags['ses:configuration-set'] = [$message['cs']];
        }
        $tags['ses:source-ip'] = ['192.0.2.10'];
        $tags['ses:from-domain'] = [substr((string) strrchr($source, '@'), 1)];
        $tags['ses:caller-identity'] = ['sesame'];
        foreach ((array) ($message['tags'] ?? []) as $name => $values) {
            $tags[$name] = array_values((array) $values);
        }

        $headers = (array) ($message['hdr'] ?? []);
        $mail = [
            'timestamp' => self::iso((int) $message['t']),
            'source' => $source,
            'sourceArn' => sprintf('arn:aws:ses:%s:%s:identity/%s', $region, $accountNumber, substr((string) strrchr($source, '@'), 1) ?: $source),
            'sendingAccountId' => $accountNumber,
            'messageId' => $message['id'],
            'destination' => $message['dst'],
            'headersTruncated' => false,
            'headers' => $headers,
            'commonHeaders' => $message['ch'] ?? ['from' => [$source], 'to' => $message['dst']],
            'tags' => $tags,
        ];
        if (is_string($message['tn'] ?? null)) {
            $mail['tenantName'] = $message['tn'];
        }

        $at = self::iso((int) $event['due']);
        $recipient = $event['rcpt'] ?? null;
        $feedbackId = sprintf('0100%012x-%s-000000', (int) $event['due'], $event['sns']);

        $detail = match ($event['type']) {
            'Send' => ['send' => (object) []],
            'Delivery' => ['delivery' => [
                'timestamp' => $at,
                'processingTimeMillis' => max(0, (int) $event['due'] - (int) $message['t']),
                'recipients' => [$recipient],
                'smtpResponse' => '250 2.6.0 Message received',
                'reportingMTA' => 'a8-30.smtp-out.amazonses.com',
                'remoteMtaIp' => '198.51.100.25',
            ]],
            'Bounce' => ['bounce' => [
                'bounceType' => $event['bt'],
                'bounceSubType' => $event['sub'],
                'bouncedRecipients' => [array_filter([
                    'emailAddress' => $recipient,
                    'action' => 'failed',
                    'status' => $event['bt'] === 'Permanent' ? '5.1.1' : '4.4.7',
                    'diagnosticCode' => $event['bt'] === 'Permanent' ? 'smtp; 550 5.1.1 user unknown' : 'smtp; 452 4.2.2 mailbox full',
                ])],
                'timestamp' => $at,
                'feedbackId' => $feedbackId,
                'reportingMTA' => 'dns; a8-30.smtp-out.amazonses.com',
            ]],
            'Complaint' => ['complaint' => array_filter([
                'complainedRecipients' => [['emailAddress' => $recipient]],
                'timestamp' => $at,
                'feedbackId' => $feedbackId,
                'userAgent' => 'Sesame Feedback Loop',
                'complaintFeedbackType' => $event['sub'] ?? null,
                'arrivalDate' => $at,
                'complaintSubType' => null,
            ], fn ($v) => $v !== null)],
            'DeliveryDelay' => ['deliveryDelay' => [
                'timestamp' => $at,
                'delayType' => $event['sub'],
                'expirationTime' => self::iso((int) $message['t'] + 86_400_000),
                'delayedRecipients' => [['emailAddress' => $recipient, 'status' => '4.4.1', 'diagnosticCode' => 'smtp; 421 4.4.1 try again later']],
            ]],
            default => throw new \InvalidArgumentException('Unsupported SES event type.'),
        };

        return ['eventType' => $event['type'], 'mail' => $mail] + $detail;
    }

    public static function iso(int $milliseconds): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($milliseconds, 1000)).sprintf('.%03dZ', $milliseconds % 1000);
    }

    /** @param array<string, mixed> $message */
    private static function sourceAddress(array $message): string
    {
        $from = $message['src'] ?? ($message['ch']['from'][0] ?? 'unknown@example.com');

        return preg_match('/<([^>]+)>\s*\z/', (string) $from, $m) === 1 ? $m[1] : (string) $from;
    }
}
