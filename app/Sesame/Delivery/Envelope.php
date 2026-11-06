<?php

namespace App\Sesame\Delivery;

use App\Sesame\Sns\SesEvent;
use App\Sesame\Sns\Signer;

/** The signed SNS HTTP POST for one event: headers and body, as SNS sends them. */
final class Envelope
{
    /** @return array{0: array<string, string>, 1: string} */
    public static function for(object $event, object $account, int $nowMs): array
    {
        $payload = json_decode((string) $event->payload, true);
        $common = [
            'MessageId' => $event->sns_message_id,
            'TopicArn' => $account->topic_arn,
            'Timestamp' => SesEvent::iso($nowMs),
            'SigningCertURL' => rtrim((string) config('sesame.public_url'), '/').'/sns/'.$account->id.'/cert.pem',
        ];
        $headers = [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'User-Agent' => 'Amazon Simple Notification Service Agent',
            'x-amz-sns-message-id' => $event->sns_message_id,
            'x-amz-sns-topic-arn' => $account->topic_arn,
        ];

        if ($event->type === 'SubscriptionConfirmation') {
            $envelope = ['Type' => 'SubscriptionConfirmation'] + $common + [
                'Token' => $payload['Token'],
                'Message' => "You have chosen to subscribe to the topic {$account->topic_arn}.\nTo confirm the subscription, visit the SubscribeURL included in this message.",
                'SubscribeURL' => self::actionUrl($account, ['Action' => 'ConfirmSubscription', 'TopicArn' => $account->topic_arn, 'Token' => $payload['Token']]),
            ];
            $headers['x-amz-sns-message-type'] = 'SubscriptionConfirmation';
        } else {
            $envelope = ['Type' => 'Notification'] + $common + [
                'Message' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'UnsubscribeURL' => self::actionUrl($account, ['Action' => 'Unsubscribe', 'SubscriptionArn' => $account->subscription_arn]),
            ];
            $headers['x-amz-sns-message-type'] = 'Notification';
            $headers['x-amz-sns-subscription-arn'] = (string) $account->subscription_arn;
        }

        $signed = Signer::sign($envelope, decrypt($account->private_key, false));

        return [$headers, json_encode($signed, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)];
    }

    /** @param array<string, string|null> $query */
    private static function actionUrl(object $account, array $query): string
    {
        return rtrim((string) config('sesame.public_url'), '/').'/sns/'.$account->id.'/?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
