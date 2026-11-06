# Sesame

A simulated Amazon SES for staging load runs. Sesame accepts SES v2 `SendEmail` requests, draws an
outcome for every recipient from a seeded lottery, and POSTs SNS notifications signed in the real
format back to your webhook, where `aws/aws-php-sns-message-validator` accepts them once it is
bound to Sesame's certificate host. Sesame keeps the ground truth, so after a run you can check
that every event reached your application and changed the right state.

It is a standalone Scalpels app. Growth CRM is its first consumer.

**Status: proof of concept.** Read [`docs/poc-report.md`](docs/poc-report.md) for what was
demonstrated, the runtime benchmark, the limitations and the remaining work. The source
specification is [`docs/source/`](docs/source/).

## What a consumer changes

Only the same two calls as production cross the boundary: `SendEmail` going out and signed SNS
POSTs coming back. A Laravel consumer using the stock `ses-v2` mailer needs:

```php
// config/services.php
'ses' => [
    // ...
    'endpoint' => env('SES_ENDPOINT'),                     // staging: https://<sesame-host>
],
'sns' => [
    'cert_host_pattern' => env('SNS_CERT_HOST_PATTERN'),  // staging: /^<sesame-host>$/
],
```

and a container-bound validator, plus a guard that keeps simulator config out of production:

```php
$this->app->singleton(MessageValidator::class, fn () => new MessageValidator(
    hostNamePattern: config('services.sns.cert_host_pattern') ?? '',
));

if (app()->isProduction() && (config('services.ses.endpoint') || config('services.sns.cert_host_pattern'))) {
    throw new RuntimeException('SES simulator config present in production.');
}
```

`app/CrmHarness` is a working example of the consumer side, including the webhook.

## Run it locally

Needs PHP 8.4+ with `pdo_pgsql`, and Postgres. The commands below run the simulator and the local
CRM harness together, which is the setup the PoC's 30/s plateau used.

```bash
composer install
cp .env.example .env && php artisan key:generate
# point DB_* at a local Postgres database, then:
php artisan migrate
php artisan octane:install --server=frankenphp
```

Sesame must be served over HTTPS, because the SNS validator only fetches certificates from `https`
URLs that end in `.pem`. Locally, FrankenPHP's internal CA does this:

```dotenv
SESAME_PUBLIC_URL=https://sesame.localhost:8443
SES_ENDPOINT=https://sesame.localhost:8443
SNS_CERT_HOST_PATTERN='/^sesame\.localhost$/'
SESAME_CA_BUNDLE=$HOME/.local/share/caddy/pki/authorities/local/root.crt
```

```bash
# Sesame: ingest and SNS endpoints
php artisan octane:frankenphp --host=sesame.localhost --port=8443 --https --admin-port=2020
# Sesame: data plane (WAL ingest, timing wheel, signing, delivery)
php artisan sesame:dataplane
# Local CRM harness: webhook, send workers, event workers
OCTANE_STATE_FILE=storage/logs/octane-harness-state.json \
  php artisan octane:frankenphp --host=127.0.0.1 --port=8001 --admin-port=2021
php artisan queue:work --queue=crm-send --sleep=0.1 --tries=0      # run several
php artisan queue:work --queue=crm-events --sleep=0.1 --tries=5

# Create a simulated account; registering the webhook sends a SubscriptionConfirmation.
php artisan sesame:account:create growth-staging \
  --webhook=http://127.0.0.1:8001/crm-harness/sns --max-rate=200 --seed=20261106 --json > creds.json
php artisan crm:harness:connect creds.json

# A 15-minute plateau at 30/s, then reconcile once late events have drained.
php artisan crm:load --rate=30 --duration=900 --run=plateau
php artisan crm:reconcile --run=plateau --wait=1800 --json=report.json
```

## Commands

| Command | Purpose |
| --- | --- |
| `sesame:account:create` | Create a simulated account. Prints its access key ID, a secret, and a truth token, once. |
| `sesame:run:start` | Start a new run on an account. `--seed` replays an earlier run's draws. |
| `sesame:dataplane` | The long-running data plane. |
| `sesame:run:report` | Simulator-side summary of a run. `--save` stores it on the run row, which outlives raw data. |
| `sesame:prune` | Delete raw data older than 7 days. Scheduled daily. |
| `crm:harness:connect`, `crm:load`, `crm:reconcile` | Local CRM harness only. |

## HTTP surface

| Route | Purpose |
| --- | --- |
| `POST /v2/email/outbound-emails` | SES v2 `SendEmail` with `Content.Raw`. The SigV4 access key ID selects the account; signatures are not verified. |
| `GET /sns/{account}/cert.pem` | The account's signing certificate. |
| `GET /sns/{account}/?Action=ConfirmSubscription&...` | The `SubscribeURL`. |
| `GET /sesame/v1/runs/{run}/truth` | Ground truth per `MessageId`, keyset-paginated. `Authorization: Bearer <truth token>`. |
| `GET /sesame/v1/runs/{run}/summary` | Simulator-side metrics for a run. Same token. |

## Tests

```bash
php artisan test        # needs Postgres; phpunit.xml uses the sesame_test database
composer stan
```

Every SNS message type is signed by Sesame and verified with the real AWS validator package, so the
signing format cannot drift unnoticed.
