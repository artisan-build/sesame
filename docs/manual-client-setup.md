# Manual staging-client setup

Sesame is not a normal Scalpels connected-app provider. On the installed Sesame deployment, use **Set up a staging client**. Choose a unique account name, preset and send rate, confirm staging-only use, and copy or download the one-time result. Scalpels does not select a consumer Cloud/Forge environment, write its variables, create a consumer PR or deploy it. One simulated account per staging application keeps runs and reconciliation separate.

The screen provides a copyable Laravel `.env` block and portable SESv2 settings for other SDKs. A consumer must use SES **v2** `SendEmail` with `Content.Raw`, an explicit HTTPS Sesame endpoint, region and explicit SDK credentials. SMTP and SESv1 are not supported. Keep TLS verification enabled and use synthetic addresses such as `recipient@example.test`.

## Staging testing only — never use in production

Copy the generated environment block into staging only. It sets `MAIL_MAILER=ses`, a synthetic `MAIL_FROM_ADDRESS`, `AWS_ENDPOINT_URL_SESV2`, `AWS_DEFAULT_REGION`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, an empty `AWS_SESSION_TOKEN`, and optional `SESAME_TRUTH_TOKEN` for reconciliation. AWS credentials also affect S3 and queues sharing those variables; keep their real staging credentials separate. Do not put a real mailer in a failover chain behind Sesame.

Use Laravel's native transport. If needed, set `'ses' => ['transport' => 'ses-v2']` inside `config/mail.php`'s `mailers`, and add `'endpoint' => env('AWS_ENDPOINT_URL_SESV2')` inside `config/services.php`'s existing `ses` array alongside the standard key/secret/region entries. Mapping the endpoint into config is important: Laravel does not load `.env` at runtime when config is cached. Remove hard-coded SES endpoint, session-token or production ConfigurationSetName overrides. Rebuild Laravel's config cache and restart long-running workers after changes. No client package, service provider changes or copied boot code is required.

For other frameworks, pass the downloaded JSON's explicit endpoint, region and credentials to the SESv2 SDK in staging. Node and Python examples are provided as instructions. Staging-only use is an operator decision, not a boot-time enforcement mechanism installed by this flow.

The access key ID is sensitive: Sesame uses it to select the account and **does not verify SigV4 signatures**. The generated secret is only for AWS SDK compatibility, not AWS-grade authentication. The truth token authorizes that account's run reports and is stored only as a hash. Do not commit, log or share the downloaded bundle. Scalpels does not retain a credential history; save the result securely before leaving the result page. Creation cannot be retried automatically after a lost response. The same account name will refuse another creation rather than silently issue more credentials; inspect the existing account before proceeding.

## Optional SNS webhook: a separate explicit step

Credential creation does not register or contact a webhook. After the staging consumer is configured and its SNS handler is ready, an operator on the Sesame installation may run:

```sh
php artisan sesame:account:subscribe growth-staging https://staging.example.test/ses-webhook --staging-only
```

This queues a `SubscriptionConfirmation`. Re-running the command replaces the pending/confirmed subscription and requires confirmation again. Before registration, configure the staging consumer to:

1. Accept only its expected TopicArn and the exact account certificate URL shown in the bundle. Restrict certificate fetching to that URL, without following redirects; keep TLS verification on.
2. Verify the SNS message signature and pin the SubscribeURL to the exact Sesame origin and `/sns/{account_id}/` path, with `Action=ConfirmSubscription` and the expected TopicArn. Do not use a permissive certificate-host pattern or disable SNS validation.
3. Follow the verified SubscribeURL without redirects. Do **not** call the real AWS SNS `ConfirmSubscription` API; Sesame's confirmation endpoint is part of the simulator.
4. Handle duplicate SNS delivery idempotently. Nothing but subscription confirmation is delivered until the subscription is confirmed. Preserve the ordinary AWS validator whenever simulator mode is disabled.

Never register a production webhook or test against real recipients. See the [working local harness](../app/CrmHarness) for signature verification and reconciliation, not as a package to install in a consumer.

## Management API contract

`POST /sesame/v1/clients` accepts the installation's **current ownership-linked BfC bearer**. A human cookie login, arbitrary admin bearer, product MCP credential or delegated assertion is insufficient. Use HTTPS and do not place credentials in URLs. Scalpels uses its existing ownership vault; the owner token is never part of the client bundle.

```json
{
  "name": "growth-staging",
  "preset": "healthy",
  "max_send_rate": 200,
  "staging_only": true
}
```

Names use lowercase letters, digits and dashes, starting with a letter or digit, at most 63 characters. Presets come from Sesame's configured presets. Send rate is 1–100000. Unknown fields, including webhook/consumer targets, are refused. The endpoint requires a valid configured HTTPS public origin (no path, credentials, query or fragment, and never an AWS endpoint) and region.

The 201, `Cache-Control: no-store, private` response contains `account_id`, `name`, initial `run_id`, `endpoint`, `region`, `access_key_id`, `secret_access_key`, `truth_token`, `topic_arn`, `certificate_url`, `subscription_status` (`none`), `preset`, and `max_send_rate`. Run ID is initial metadata, not a permanent client environment variable: future runs have new IDs. No signing keys, BfC credentials or subscription tokens are returned. No schema migration is required; existing accounts and runs are unchanged.

401/403 refuses missing, dead, wrong-purpose or non-owner credentials; 409 refuses a name already in use (including a concurrent duplicate); 422 refuses invalid input; 503 means public URL/region configuration needs repair. A timeout, 5xx, or malformed successful response can leave an account created but its one-time result unavailable. Inspect the installation before retrying; do not work around this by automatically inventing a new name. These credentials are not issued through MCP; all five MCP tools remain read-only.
