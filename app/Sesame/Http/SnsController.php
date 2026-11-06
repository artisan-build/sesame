<?php

namespace App\Sesame\Http;

use App\Models\SesameAccount;
use App\Sesame\Accounts;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The SNS side of the simulator: the signing certificate and the
 * SubscribeURL/UnsubscribeURL actions SNS would serve.
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

            return new Response(
                '<ConfirmSubscriptionResponse xmlns="http://sns.amazonaws.com/doc/2010-03-31/"><ConfirmSubscriptionResult><SubscriptionArn>'
                .e((string) $row->subscription_arn).'</SubscriptionArn></ConfirmSubscriptionResult><ResponseMetadata><RequestId>'
                .$requestId.'</RequestId></ResponseMetadata></ConfirmSubscriptionResponse>',
                200,
                ['Content-Type' => 'text/xml'],
            );
        }

        if ($request->query('Action') === 'Unsubscribe') {
            // UnsubscribeConfirmation delivery is later work; v1 only acknowledges.
            return $this->error('NotImplemented', 'Unsubscribe is not simulated yet.', 501, $requestId);
        }

        return $this->error('InvalidAction', 'Unsupported action.', 400, $requestId);
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
