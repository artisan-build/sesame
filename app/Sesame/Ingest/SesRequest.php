<?php

namespace App\Sesame\Ingest;

/**
 * Reads the parts of an SES v2 SendEmail (Content.Raw) request the simulator
 * uses. Only the MIME header block is decoded and parsed, never the body.
 */
final class SesRequest
{
    /** @param list<string> $recipients */
    private function __construct(
        public readonly array $recipients,
        public readonly ?string $configurationSet,
        /** @var array<string, list<string>> */
        public readonly array $tags,
        public readonly ?string $tenantName,
        public readonly ?string $source,
        /** @var list<array{name: string, value: string}> */
        public readonly array $headers,
        public readonly int $rawBytes,
    ) {}

    /** Access key ID from a SigV4 Authorization header, or null when the header is not SigV4. */
    public static function accessKeyId(?string $authorization): ?string
    {
        if ($authorization === null
            || preg_match('~\AAWS4-HMAC-SHA256\s+Credential=([A-Z0-9]{16,128})/\d{8}/[a-z0-9-]+/[a-z0-9-]+/aws4_request\s*,\s*SignedHeaders=[a-z0-9;\-]+\s*,\s*Signature=[0-9a-f]{64}\z~', $authorization, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /** @throws SesError */
    public static function parse(string $body): self
    {
        $payload = json_decode($body, true, 16);
        if (! is_array($payload)) {
            throw SesError::badRequest('Request body is not valid JSON.');
        }
        $raw = $payload['Content']['Raw']['Data'] ?? null;
        if (! is_string($raw) || $raw === '') {
            // v1 only handles Content.Raw: Laravel's ses-v2 mailer never sends anything else.
            throw SesError::badRequest('Only Content.Raw is supported by this simulator.');
        }

        $headers = self::headers($raw);
        $destination = $payload['Destination'] ?? null;
        $recipients = [];
        if (is_array($destination)) {
            foreach (['ToAddresses', 'CcAddresses', 'BccAddresses'] as $key) {
                foreach ((array) ($destination[$key] ?? []) as $address) {
                    $recipients[] = is_string($address) ? self::address($address) : '';
                }
            }
        } else {
            // Raw sends may leave Destination out; SES then reads the MIME headers.
            foreach ($headers as $header) {
                if (in_array(strtolower($header['name']), ['to', 'cc', 'bcc'], true)) {
                    foreach (self::splitAddresses($header['value']) as $address) {
                        $recipients[] = self::address($address);
                    }
                }
            }
        }
        if ($recipients === []) {
            throw SesError::badRequest('Missing required recipient: Destination.ToAddresses.');
        }
        foreach ($recipients as $recipient) {
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                throw SesError::badRequest('Illegal address');
            }
        }

        $tags = [];
        foreach ((array) ($payload['EmailTags'] ?? []) as $tag) {
            if (is_array($tag) && is_string($tag['Name'] ?? null) && is_string($tag['Value'] ?? null)) {
                $tags[$tag['Name']][] = $tag['Value'];
            }
        }

        return new self(
            array_values(array_unique($recipients)),
            is_string($payload['ConfigurationSetName'] ?? null) ? $payload['ConfigurationSetName'] : null,
            $tags,
            is_string($payload['TenantName'] ?? null) ? $payload['TenantName'] : null,
            // Laravel sends a v1-style Source alongside v2 parameters; tolerate it.
            is_string($payload['FromEmailAddress'] ?? null) ? $payload['FromEmailAddress'] : (is_string($payload['Source'] ?? null) ? $payload['Source'] : null),
            $headers,
            (int) (strlen($raw) * 3 / 4),
        );
    }

    /** @return array{from: list<string>, to: list<string>, messageId?: string, subject?: string, date?: string} */
    public function commonHeaders(): array
    {
        $common = ['from' => [], 'to' => []];
        foreach ($this->headers as $header) {
            match (strtolower($header['name'])) {
                'from' => $common['from'] = self::splitAddresses($header['value']),
                'to' => $common['to'] = self::splitAddresses($header['value']),
                'message-id' => $common['messageId'] = $header['value'],
                'subject' => $common['subject'] = $header['value'],
                'date' => $common['date'] = $header['value'],
                default => null,
            };
        }

        return $common;
    }

    /**
     * Decode just enough base64 to reach the blank line that ends the header block.
     *
     * @return list<array{name: string, value: string}>
     */
    private static function headers(string $base64): array
    {
        $block = null;
        foreach ([8192, 65536, PHP_INT_MAX] as $chars) {
            $chunk = $chars >= strlen($base64) ? $base64 : substr($base64, 0, $chars);
            $decoded = base64_decode($chunk, true);
            if ($decoded === false) {
                throw SesError::badRequest('Content.Raw.Data is not valid base64.');
            }
            $end = strpos($decoded, "\r\n\r\n");
            $end = $end === false ? strpos($decoded, "\n\n") : $end;
            if ($end !== false) {
                $block = substr($decoded, 0, $end);
                break;
            }
            if ($chunk === $base64) {
                $block = $decoded;
                break;
            }
        }

        $headers = [];
        // Unfold continuation lines, then split name: value.
        foreach (preg_split('/\r?\n(?![ \t])/', (string) $block) ?: [] as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $headers[] = [
                'name' => substr($line, 0, $pos),
                'value' => trim((string) preg_replace('/\r?\n[ \t]+/', ' ', substr($line, $pos + 1))),
            ];
        }

        return $headers;
    }

    /** @return list<string> */
    private static function splitAddresses(string $value): array
    {
        return array_values(array_filter(array_map('trim', str_getcsv($value, ',', '"', '')), fn ($v) => $v !== ''));
    }

    /** Extract the bare address from `Name <addr>` forms. */
    private static function address(string $value): string
    {
        return preg_match('/<([^>]+)>\s*\z/', $value, $m) === 1 ? trim($m[1]) : trim($value);
    }
}
