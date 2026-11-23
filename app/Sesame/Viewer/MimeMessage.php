<?php

namespace App\Sesame\Viewer;

/**
 * The HTML and plain-text parts of a kept raw message, decoded for the
 * viewer: multipart nesting, base64 and quoted-printable transfer encodings,
 * and the declared charset. Attachments are not shown.
 */
final readonly class MimeMessage
{
    private function __construct(public ?string $html, public ?string $text) {}

    public static function parse(string $mime): self
    {
        $html = $text = null;
        self::walk($mime, $html, $text);

        return new self($html, $text);
    }

    private static function walk(string $entity, ?string &$html, ?string &$text): void
    {
        [$head, $body] = preg_split('/\r?\n\r?\n/', $entity, 2) + [1 => ''];
        $headers = [];
        foreach (preg_split('/\r?\n(?![ \t])/', $head) ?: [] as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[strtolower(trim(substr($line, 0, $pos)))] = trim((string) preg_replace('/\r?\n[ \t]+/', ' ', substr($line, $pos + 1)));
            }
        }
        $type = strtolower(trim(explode(';', $headers['content-type'] ?? 'text/plain')[0]));
        $disposition = strtolower(trim(explode(';', $headers['content-disposition'] ?? '')[0]));

        if (str_starts_with($type, 'multipart/')) {
            $boundary = self::parameter($headers['content-type'] ?? '', 'boundary');
            if ($boundary === null) {
                return;
            }
            $parts = preg_split('/\r?\n--'.preg_quote($boundary, '/').'(?:--)?[ \t]*(?=\r?\n|\z)/', "\n".$body) ?: [];
            foreach (array_slice($parts, 1) as $part) {
                self::walk(ltrim($part, "\r\n"), $html, $text);
            }

            return;
        }
        if ($disposition === 'attachment' || ! in_array($type, ['text/html', 'text/plain'], true)) {
            return;
        }

        $decoded = match (strtolower($headers['content-transfer-encoding'] ?? '7bit')) {
            'base64' => (string) base64_decode((string) preg_replace('/\s+/', '', $body)),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
        $charset = self::parameter($headers['content-type'] ?? '', 'charset') ?? 'us-ascii';
        if (! in_array(strtolower($charset), ['utf-8', 'us-ascii'], true) && in_array(strtoupper($charset), array_map(strtoupper(...), mb_list_encodings()), true)) {
            $decoded = (string) mb_convert_encoding($decoded, 'UTF-8', $charset);
        }

        if ($type === 'text/html') {
            $html ??= $decoded;
        } else {
            $text ??= $decoded;
        }
    }

    private static function parameter(string $header, string $name): ?string
    {
        return preg_match('/;\s*'.$name.'\s*=\s*(?:"([^"]*)"|([^;\s]+))/i', $header, $m) === 1 ? ($m[1] !== '' ? $m[1] : $m[2]) : null;
    }
}
