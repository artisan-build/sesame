<?php

namespace App\Sesame\Ingest;

use App\Sesame\Outcome;
use App\Sesame\OutcomeLottery;
use App\Sesame\Wal\WriteAheadLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * POST /v2/email/outbound-emails — SES v2 SendEmail with Content.Raw.
 *
 * Fast acknowledgement: verify the request shape, draw outcomes, append to
 * the write-ahead log and return the MessageId, all before any database
 * write. The data plane does everything else.
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
            if ($account['paused']) {
                throw SesError::paused();
            }
            if (! RateGate::admit((string) $account['id'], (int) $account['max_send_rate'])) {
                $this->log($account, 'throttled');
                throw SesError::throttled();
            }

            $send = SesRequest::parse($request->getContent());
            $outcomes = [];
            foreach ($send->recipients as $recipient) {
                $outcomes[$recipient] = OutcomeLottery::draw((int) $account['seed'], $recipient, $account['rates'], $account['timing']);
                if ($outcomes[$recipient]->kind === Outcome::REJECT) {
                    $this->log($account, 'rejected');
                    throw SesError::rejected();
                }
            }

            $acceptedMs = (int) floor(microtime(true) * 1000);
            $messageId = sprintf('0100%012x-%s-000000', $acceptedMs, Str::uuid());

            WriteAheadLog::fromConfig()->append([
                'k' => 'm',
                'id' => $messageId,
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

            return new JsonResponse(['MessageId' => $messageId], 200, ['x-amzn-RequestId' => (string) Str::uuid()]);
        } catch (SesError $error) {
            return new JsonResponse(['message' => $error->getMessage()], $error->status, [
                'x-amzn-ErrorType' => $error->type,
                'x-amzn-RequestId' => (string) Str::uuid(),
            ]);
        }
    }

    /**
     * Throttles and rejections are counted through the same log, still without a database write.
     *
     * @param  array<string, mixed>  $account
     */
    private function log(array $account, string $kind): void
    {
        WriteAheadLog::fromConfig()->append(['k' => $kind, 'a' => $account['id'], 'r' => $account['run_id'], 't' => (int) floor(microtime(true) * 1000)]);
    }
}
