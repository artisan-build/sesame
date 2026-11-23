<?php

namespace App\Sesame\Viewer;

use App\Models\SesameAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * GET /sesame/v1/accounts/{account}/messages/{message}?expires=…&signature=…
 *
 * Shows a kept message to anyone holding a link the client signed with the
 * account's viewer key (sesame:account:viewer-key). The page behaves like a
 * mail client: links open in a new tab and the message's own images, open
 * pixel and unsubscribe links work, so viewing has the same effects as a
 * recipient reading it. Scripts in the message never run.
 */
final class MessageViewController
{
    public function __invoke(Request $request, string $account, string $message): Response
    {
        $expires = (int) $request->query('expires', '0');
        $signature = (string) $request->query('signature', '');
        $key = SesameAccount::query()->find($account)?->viewer_key;
        if (! is_string($key) || $key === '' || ! hash_equals(self::signature($key, $account, $message, $expires), $signature)) {
            return $this->page('sesame.message-unavailable', ['reason' => 'This link is not valid.'], 403);
        }
        if ($expires < time()) {
            return $this->page('sesame.message-unavailable', ['reason' => 'This link has expired. Ask for the message again to get a new link.'], 410);
        }
        $row = DB::table('sesame_messages')->where('message_id', $message)->where('account_id', $account)->first();
        if ($row === null || $row->raw === null) {
            return $this->page('sesame.message-unavailable', ['reason' => $row === null
                ? 'Sesame no longer holds this message. Messages are removed after '.config('sesame.retention_days').' days.'
                : 'This message was sent without asking Sesame to keep its body.'], 404);
        }

        $parsed = MimeMessage::parse((string) base64_decode((string) $row->raw));
        /** @var list<array{name: string, value: string}> $headers */
        $headers = array_map(fn (array $h): array => ['name' => $h['name'], 'value' => self::decodeHeader($h['value'])],
            json_decode((string) $row->headers, true));
        $header = fn (string $name): ?string => collect($headers)->first(fn (array $h): bool => strcasecmp($h['name'], $name) === 0)['value'] ?? null;
        $oneClick = null;
        if (stripos((string) $header('List-Unsubscribe-Post'), 'List-Unsubscribe=One-Click') !== false
            && preg_match('/<(https:[^>]+)>/i', (string) $header('List-Unsubscribe'), $m) === 1) {
            $oneClick = $m[1];
        }
        $html = $parsed->html ?? '<pre style="white-space:pre-wrap;font:14px/1.5 ui-monospace,monospace;margin:16px">'.e((string) $parsed->text).'</pre>';

        return $this->page('sesame.message', [
            'subject' => $header('Subject'),
            'headers' => collect($headers)->filter(fn (array $h): bool => in_array(strtolower($h['name']), ['from', 'reply-to', 'to', 'subject', 'date', 'list-unsubscribe', 'list-unsubscribe-post'], true))->values()->all(),
            'acceptedAt' => $row->accepted_at,
            'outcomes' => json_decode((string) $row->outcomes, true),
            'text' => $parsed->text,
            // The open pixel loads in one pane only, so one view is one open.
            'desktop' => self::opensLinksInNewTab($html),
            'mobile' => self::opensLinksInNewTab(self::withoutPixels($html)),
            'oneClick' => $oneClick,
        ], 200);
    }

    /** The link signature the client computes: HMAC-SHA256 over account, message and expiry, one per line. */
    public static function signature(string $key, string $account, string $message, int $expires): string
    {
        return hash_hmac('sha256', $account."\n".$message."\n".$expires, $key);
    }

    /** RFC 2047 encoded words (=?utf-8?Q?…?=), as a mail client shows them. */
    private static function decodeHeader(string $value): string
    {
        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded === false ? $value : $decoded;
    }

    /** What a mail client does: a link leaves the message for a new tab. */
    private static function opensLinksInNewTab(string $html): string
    {
        $base = '<base target="_blank">';
        $withBase = preg_replace('/<head\b[^>]*>/i', '$0'.$base, $html, 1, $count);

        return $count === 1 ? (string) $withBase : $base.$html;
    }

    /** Drop 1×1 images, the shape of an open-tracking pixel. */
    private static function withoutPixels(string $html): string
    {
        $one = '\s*=\s*["\']?1(?:px)?["\']?(?=[\s>\/])';

        return (string) preg_replace('/<img\b(?=[^>]*\bwidth'.$one.')(?=[^>]*\bheight'.$one.')[^>]*>/i', '', $html);
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data, int $status): Response
    {
        return response()->view($view, $data, $status)->withHeaders([
            'Cache-Control' => 'no-store, private',
            // The signed URL must not reach the sites the message links to.
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
