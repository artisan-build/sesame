<?php

namespace App\Sesame\Sns;

use RuntimeException;

/**
 * Signs SNS envelopes the way SNS does for SignatureVersion 2: RSA with
 * SHA256 over "key\nvalue\n" for the signable keys of the message type, in
 * this order. The test suite checks every type with the real AWS validator.
 */
final class Signer
{
    private const array SIGNABLE = [
        'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
        'SubscriptionConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
        'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
    ];

    /** @var array<string, \OpenSSLAsymmetricKey> */
    private static array $keys = [];

    /**
     * @param  array<string, string>  $envelope  without SignatureVersion/Signature
     * @return array<string, string>
     */
    public static function sign(array $envelope, string $privateKeyPem): array
    {
        $envelope['SignatureVersion'] = '2';
        $stringToSign = '';
        foreach (self::SIGNABLE[$envelope['Type']] ?? throw new RuntimeException('Unsupported SNS type.') as $key) {
            if (array_key_exists($key, $envelope)) {
                $stringToSign .= $key."\n".$envelope[$key]."\n";
            }
        }

        $cacheKey = hash('sha256', $privateKeyPem);
        $key = self::$keys[$cacheKey] ??= openssl_pkey_get_private($privateKeyPem) ?: throw new RuntimeException('Invalid signing key.');
        if (! openssl_sign($stringToSign, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the SNS envelope.');
        }
        $envelope['Signature'] = base64_encode($signature);

        return $envelope;
    }
}
