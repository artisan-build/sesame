<?php

namespace App\Sesame;

use App\Models\SesameAccount;
use App\Models\SesameRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Control-plane operations on simulated accounts and runs. */
final class Accounts
{
    /**
     * @return array{account: SesameAccount, access_key_id: string, secret_access_key: string, truth_token: string}
     */
    public static function create(string $name, ?string $webhookUrl = null, string $preset = 'healthy', ?int $maxSendRate = null, ?int $seed = null): array
    {
        self::assertPreset($preset);
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,62}\z/', $name) !== 1) {
            throw new InvalidArgumentException('Account names are lowercase letters, digits and dashes.');
        }

        $accessKeyId = 'AKIA'.strtoupper(Str::random(16));
        $accessKeyId = (string) preg_replace('/[^A-Z0-9]/', '7', $accessKeyId);
        $secret = Str::random(40);
        $truthToken = Str::random(48);
        $number = (string) random_int(100000000000, 999999999999);
        [$privateKey, $certificate] = self::keyPair();

        $account = DB::transaction(function () use ($name, $preset, $maxSendRate, $accessKeyId, $truthToken, $number, $privateKey, $certificate, $seed): SesameAccount {
            $account = SesameAccount::create([
                'name' => $name,
                'access_key_id' => $accessKeyId,
                'truth_token_hash' => hash('sha256', $truthToken),
                'preset' => $preset,
                'max_send_rate' => $maxSendRate ?? (int) config('sesame.defaults.max_send_rate'),
                'aws_account_number' => $number,
                'topic_arn' => sprintf('arn:aws:sns:%s:%s:sesame-%s', config('sesame.region'), $number, $name),
                'private_key' => $privateKey,
                'certificate' => $certificate,
            ]);
            self::startRun($account, $seed);

            return $account;
        });

        if ($webhookUrl !== null) {
            self::subscribe($account, $webhookUrl);
        }

        return ['account' => $account->refresh(), 'access_key_id' => $accessKeyId, 'secret_access_key' => $secret, 'truth_token' => $truthToken];
    }

    public static function startRun(SesameAccount $account, ?int $seed = null, ?string $preset = null, ?string $label = null): SesameRun
    {
        $preset ??= $account->preset;
        self::assertPreset($preset);

        return DB::transaction(function () use ($account, $seed, $preset, $label): SesameRun {
            if ($account->current_run_id !== null) {
                SesameRun::whereKey($account->current_run_id)->whereNull('ended_at')->update(['ended_at' => now()]);
            }
            $run = SesameRun::create([
                'account_id' => $account->id,
                'seed' => $seed ?? random_int(1, PHP_INT_MAX),
                'preset' => $preset,
                'label' => $label,
                'started_at' => now(),
            ]);
            $account->forceFill(['current_run_id' => $run->id])->save();

            return $run;
        });
    }

    /**
     * Registering a webhook starts the SNS subscription lifecycle: nothing is
     * delivered until the endpoint GETs the SubscribeURL it is sent.
     */
    public static function subscribe(SesameAccount $account, string $webhookUrl): void
    {
        if (filter_var($webhookUrl, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($webhookUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new InvalidArgumentException('The webhook must be an http(s) URL.');
        }
        $account->forceFill([
            'webhook_url' => $webhookUrl,
            'subscription_status' => 'pending',
            'subscription_token' => bin2hex(random_bytes(64)),
            'subscription_arn' => $account->topic_arn.':'.Str::uuid(),
            'subscription_requested_at' => now(),
            'subscription_confirmed_at' => null,
        ])->save();
    }

    public static function confirm(SesameAccount $account, string $token, string $topicArn): bool
    {
        if ($account->subscription_token === null || ! hash_equals($account->subscription_token, $token) || $topicArn !== $account->topic_arn) {
            return false;
        }
        if ($account->subscription_status !== 'confirmed') {
            $account->forceFill(['subscription_status' => 'confirmed', 'subscription_confirmed_at' => now()])->save();
        }

        return true;
    }

    private static function assertPreset(string $preset): void
    {
        if (! array_key_exists($preset, (array) config('sesame.presets'))) {
            throw new InvalidArgumentException("Unknown preset [{$preset}].");
        }
    }

    /** @return array{0: string, 1: string} PEM private key and self-signed certificate */
    private static function keyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $host = (string) (parse_url((string) config('sesame.public_url'), PHP_URL_HOST) ?: 'localhost');
        $csr = $key === false ? false : openssl_csr_new(['commonName' => $host], $key, ['digest_alg' => 'sha256']);
        $cert = $csr === false || $csr === true ? false : openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        if ($key === false || $cert === false || ! openssl_pkey_export($key, $pem) || ! openssl_x509_export($cert, $certPem)) {
            throw new RuntimeException('Could not generate the account signing key.');
        }

        return [$pem, $certPem];
    }
}
