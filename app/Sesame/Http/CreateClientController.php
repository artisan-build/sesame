<?php

namespace App\Sesame\Http;

use App\Models\SesameAccount;
use App\Sesame\Accounts;
use App\Sesame\Ingest\AccountDirectory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Owner-only manual setup: creates credentials, never configures a consumer. */
final class CreateClientController
{
    public function __invoke(Request $request): JsonResponse
    {
        // Never accept a callback here: configuring a client and initiating an
        // SNS subscription are separate, explicitly authorized operations.
        abort_if(array_diff(array_keys($request->all()), ['name', 'preset', 'max_send_rate', 'staging_only']) !== [], 422, 'Unexpected setup fields.');
        $input = $request->validate([
            'name' => ['required', 'string', 'regex:/\A[a-z0-9][a-z0-9-]{0,62}\z/'],
            'preset' => ['required', 'string', Rule::in(array_keys((array) config('sesame.presets')))],
            'max_send_rate' => ['required', 'integer', 'min:1', 'max:100000'],
            'staging_only' => ['required', 'accepted'],
        ]);

        $endpoint = rtrim((string) config('sesame.public_url'), '/');
        $parts = parse_url($endpoint);
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $aws = false;
        foreach (['amazonaws.com', 'amazonaws.com.cn', 'api.aws'] as $domain) {
            $aws = $aws || $host === $domain || str_ends_with($host, '.'.$domain);
        }
        // The returned SDK settings must never fall back to real SES or carry
        // URL credentials. A public origin is also required by the SNS URLs.
        abort_if(filter_var($endpoint, FILTER_VALIDATE_URL) === false
            || ($parts['scheme'] ?? '') !== 'https' || $host === '' || $aws
            || array_diff(array_keys($parts ?: []), ['scheme', 'host', 'port']) !== []
            || preg_match('/\A[a-z0-9-]{1,32}\z/', (string) config('sesame.region')) !== 1,
            503, 'Configure a valid HTTPS Sesame public origin and region before issuing client settings.');

        abort_if(SesameAccount::where('name', $input['name'])->exists(), 409, 'This client name already exists. Inspect the existing account before retrying.');
        try {
            $created = Accounts::create($input['name'], preset: $input['preset'], maxSendRate: (int) $input['max_send_rate']);
        } catch (UniqueConstraintViolationException) {
            // The unique name also prevents a replay after a lost response
            // from minting another account. The transaction rolls back its run.
            abort(409, 'This client name already exists. Inspect the existing account before retrying.');
        }
        AccountDirectory::forget();
        $account = $created['account'];

        return new JsonResponse([
            'account_id' => $account->id,
            'name' => $account->name,
            'run_id' => $account->current_run_id,
            'endpoint' => $endpoint,
            'region' => (string) config('sesame.region'),
            'access_key_id' => $created['access_key_id'],
            // SDK compatibility only: the ingest path selects by key ID;
            // it does not verify the SigV4 signature or this secret.
            'secret_access_key' => $created['secret_access_key'],
            'truth_token' => $created['truth_token'],
            'topic_arn' => $account->topic_arn,
            'certificate_url' => $account->certificateUrl(),
            'subscription_status' => $account->subscription_status,
            'preset' => $account->preset,
            'max_send_rate' => $account->max_send_rate,
        ], 201, ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer']);
    }
}
