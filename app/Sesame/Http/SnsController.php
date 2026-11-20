<?php

namespace App\Sesame\Http;

use App\Models\SesameAccount;
use App\Sesame\Accounts;
use App\Sesame\Ingest\SesRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The SNS side of the simulator: the signing certificate and the
 * SubscribeURL/UnsubscribeURL actions SNS would serve, and its Query API
 * ConfirmSubscription for consumers that confirm through the AWS SDK.
 */
final class SnsController
{
    public function certificate(string $account): Response
    {
        $row = SesameAccount::find($account);
        abort_if($row === null, 404);

        return new Response($row->certificate, 200, ['Content-Type' => 'application/x-x509-ca-cert', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function action(Request $request, string $account): Response
    {
        $row = SesameAccount::find($account);
        abort_if($row === null, 404);
        $requestId = (string) Str::uuid();

        if ($request->query('Action') === 'ConfirmSubscription') {
            if (! Accounts::confirm($row, (string) $request->query('Token'), (string) $request->query('TopicArn'))) {
                return $this->error('InvalidParameter', 'Invalid token', 400, $requestId);
            }

            return $this->confirmed($row, $requestId);
        }

        if ($request->query('Action') === 'Unsubscribe') {
            // UnsubscribeConfirmation delivery is later work; v1 only acknowledges.
            return $this->error('NotImplemented', 'Unsubscribe is not simulated yet.', 501, $requestId);
        }

        return $this->error('InvalidAction', 'Unsupported action.', 400, $requestId);
    }

    /**
     * POST / — the SNS Query API's ConfirmSubscription, as the stock AWS SDK
     * calls it with the endpoint pointed at Sesame. The SigV4 access key ID
     * selects the account, as for SendEmail; signatures are not verified.
     * A consumer can keep its production confirmation path (SDK call with
     * the pinned topic, SubscribeURL never fetched) unchanged in staging.
     */
    public function api(Request $request): Response
    {
        $requestId = (string) Str::uuid();
        $accessKeyId = SesRequest::accessKeyId($request->headers->get('Authorization'));
        $row = $accessKeyId === null ? null : SesameAccount::where('access_key_id', $accessKeyId)->first();
        if ($row === null) {
            return $this->error('InvalidClientTokenId', 'The security token included in the request is invalid.', 403, $requestId);
        }
        // Read the form body directly: php://input carries it under PHP-FPM, Octane and the test kernel alike.
        parse_str($request->getContent(), $input);
        if (($input['Action'] ?? null) !== 'ConfirmSubscription') {
            return $this->error('InvalidAction', 'Unsupported action.', 400, $requestId);
        }
        if (($input['TopicArn'] ?? null) !== $row->topic_arn) {
            return $this->error('NotFound', 'Topic does not exist', 404, $requestId);
        }
        if (! Accounts::confirm($row, (string) ($input['Token'] ?? ''), $row->topic_arn)) {
            return $this->error('InvalidParameter', 'Invalid token', 400, $requestId);
        }

        return $this->confirmed($row, $requestId);
    }

    private function confirmed(SesameAccount $row, string $requestId): Response
    {
        return new Response(
            '<ConfirmSubscriptionResponse xmlns="http://sns.amazonaws.com/doc/2010-03-31/"><ConfirmSubscriptionResult><SubscriptionArn>'
            .e((string) $row->subscription_arn).'</SubscriptionArn></ConfirmSubscriptionResult><ResponseMetadata><RequestId>'
            .$requestId.'</RequestId></ResponseMetadata></ConfirmSubscriptionResponse>',
            200,
            ['Content-Type' => 'text/xml', 'x-amzn-RequestId' => $requestId],
        );
    }

    private function error(string $code, string $message, int $status, string $requestId): Response
    {
        return new Response(
            '<ErrorResponse xmlns="http://sns.amazonaws.com/doc/2010-03-31/"><Error><Type>Sender</Type><Code>'.$code.'</Code><Message>'
            .e($message).'</Message></Error><RequestId>'.$requestId.'</RequestId></ErrorResponse>',
            $status,
            ['Content-Type' => 'text/xml'],
        );
    }
}
