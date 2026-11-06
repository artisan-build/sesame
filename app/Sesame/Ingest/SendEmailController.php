<?php

namespace App\Sesame\Ingest;

use App\Sesame\Outcome;
use App\Sesame\OutcomeLottery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

use function Illuminate\Support\defer;

/**
 * POST /v2/email/outbound-emails — SES v2 SendEmail with Content.Raw.
 *
 * Verify the request shape, draw outcomes, plan every event, commit them to
 * this instance's IngestBuffer and return the MessageId. The buffer drains
 * to Postgres after the response; delivery is the sweeper's and the queue's.
 */
final class SendEmailController
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $accessKeyId = SesRequest::accessKeyId($request->headers->get('Authorization'));
            $account = $accessKeyId === null ? null : AccountDirectory::find($accessKeyId);
            if ($account === null) {
                throw SesError::unrecognizedClient();
            }
            // Drain after the response is sent, on this same instance: the
            // buffer is a local file no other instance can see.
            defer(fn () => IngestBuffer::drain(), 'sesame-drain');
            if ($account['paused']) {
                throw SesError::paused();
            }
            $send = SesRequest::parse($request->getContent());
            $outcomes = [];
            foreach ($send->recipients as $recipient) {
                $outcomes[$recipient] = OutcomeLottery::draw((int) $account['seed'], $recipient, $account['rates'], $account['timing']);
                if ($outcomes[$recipient]->kind === Outcome::REJECT) {
                    IngestBuffer::refusal($account, 'rejected', (int) floor(microtime(true) * 1000));
                    throw SesError::rejected();
                }
            }

            $acceptedMs = (int) floor(microtime(true) * 1000);
            $messageId = IngestBuffer::accept($account, [
                'a' => $account['id'],
                'r' => $account['run_id'],
                't' => $acceptedMs,
                'src' => $send->source,
                'dst' => $send->recipients,
                'cs' => $send->configurationSet,
                'tn' => $send->tenantName,
                'tags' => $send->tags,
                'hdr' => $send->headers,
                'ch' => $send->commonHeaders(),
                'sz' => $send->rawBytes,
                'o' => array_map(fn (Outcome $o): array => ['k' => $o->kind, 's' => $o->subType], $outcomes),
                'ev' => EventPlan::for($acceptedMs, $outcomes),
            ]);
            if ($messageId === null) {
                throw SesError::throttled();
            }

            return new JsonResponse(['MessageId' => $messageId], 200, ['x-amzn-RequestId' => (string) Str::uuid()]);
        } catch (SesError $error) {
            return new JsonResponse(['message' => $error->getMessage()], $error->status, [
                'x-amzn-ErrorType' => $error->type,
                'x-amzn-RequestId' => (string) Str::uuid(),
            ]);
        }
    }
}
