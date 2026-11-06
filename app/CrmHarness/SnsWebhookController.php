<?php

namespace App\CrmHarness;

use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LOCAL CRM-COMPATIBLE HARNESS webhook, built the way the brief asks the CRM
 * to: build the Message from the raw text/plain body, validate it with the
 * container's MessageValidator, persist the raw envelope, return 200, and do
 * the rest in a queued job. 4xx only for invalid signatures; 5xx only for
 * failures worth retrying.
 */
final class SnsWebhookController
{
    public function __invoke(Request $request, MessageValidator $validator): Response
    {
        try {
            // Message::fromRawPostData() refuses a request without this header.
            if (! $request->headers->has('x-amz-sns-message-type')) {
                throw new \RuntimeException('SNS message type header not provided.');
            }
            $message = Message::fromJsonString($request->getContent());
            $validator->validate($message);
        } catch (Throwable $e) {
            Log::warning('crm-harness: rejected SNS message', ['reason' => $e->getMessage()]);

            return new Response('Invalid SNS message.', 403);
        }

        try {
            $inserted = DB::transaction(fn (): int => DB::table('harness_sns_receipts')->insertOrIgnore([
                'sns_message_id' => $message['MessageId'],
                'type' => $message['Type'],
                'raw' => $request->getContent(),
                'received_at' => Clock::now(),
            ]));
        } catch (Throwable) {
            return new Response('Storage unavailable.', 503); // retryable
        }
        if ($inserted === 0) {
            return new Response('', 200); // duplicate delivery: already have it
        }

        if ($message['Type'] === 'SubscriptionConfirmation') {
            try {
                // Validated above, so SubscribeURL is from the trusted signer.
                Http::connectTimeout(3)->timeout(5)
                    ->withOptions(['allow_redirects' => false, 'verify' => config('services.sns.ca_bundle') ?: true])
                    ->get($message['SubscribeURL'])->throw();
                DB::table('harness_sns_receipts')->where('sns_message_id', $message['MessageId'])->update(['processed_at' => Clock::now()]);
            } catch (Throwable) {
                DB::table('harness_sns_receipts')->where('sns_message_id', $message['MessageId'])->delete();

                return new Response('Confirmation failed.', 503);
            }

            return new Response('', 200);
        }

        if ($message['Type'] === 'Notification') {
            ProcessSnsNotification::dispatch($message['MessageId']);
        }

        return new Response('', 200);
    }
}
