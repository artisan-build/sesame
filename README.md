# Sesame

A simulated Amazon SES for staging load runs. Sesame accepts SES v2 `SendEmail` requests, draws an
outcome for every recipient from a seeded lottery, and POSTs SNS notifications signed in the real
format back to your webhook, where `aws/aws-php-sns-message-validator` accepts them once it is
bound to Sesame's certificate host. Sesame keeps the ground truth, so after a run you can check
that every event reached your application and changed the right state.

It is a first-party Scalpels app using Built for Cloud for identity, ownership and delegated MCP
access. Growth CRM is its first consumer. Existing installations must follow the
[first-party upgrade runbook](docs/first-party-upgrade.md); a catalog relabel alone is not enough.

**Status: proof of concept.** Read [`docs/poc-report.md`](docs/poc-report.md) for what was
demonstrated, the runtime benchmark, the limitations and the remaining work. The source
specification is [`docs/source/`](docs/source/).

## Read this before a test run: deploying during a run can lose sends

Sesame runs on Laravel Cloud's managed resources only: the web app, Postgres and a managed queue.
It has no scheduler, so the environment can hibernate between test runs. It has no persistent
disk, so it acknowledges each `SendEmail` from a SQLite buffer in the instance's temporary
directory and moves the send into Postgres right after the response. Each after-response drain
takes every buffered row it can claim, not just its own request's.

**A send Sesame has acknowledged but not yet moved to Postgres is lost if the instance goes
away.** That happens on every deploy, and on any unexpected instance or process loss. In tests
the window was a few milliseconds: killing the server and wiping its buffer mid-run lost 0 to 3
sends per kill at 300/s and none at 30/s. If the after-response drain cannot run (for example,
Postgres is unreachable), the window grows to whatever the buffer holds until a later request's
drain succeeds. With no scheduler, nothing drains an instance that stops getting requests.

**A deploy also briefly takes down the certificate URL.** Notifications are signed with a
certificate served from Sesame itself (`/sns/{account}/cert.pem`). A webhook that has to fetch it
while Sesame is restarting fails validation, and if it answers 4xx, SNS rules drop the event. In
the live test below, 32 of 14,642 events were dropped this way during a 2.7 s restart. These
drops show up in `crm:reconcile` as CRM mismatches ("dropped after HTTP 403"), so a deploy
during a run produces misleading findings as well as lost sends.

So:

- **Do not deploy Sesame while a test is running.** Turn off push-to-deploy on its Cloud
  environment, so that a push to the repository does not redeploy it mid-run. A Scalpels install
  turns it off for you once manifest v7 is live; until then, turn it off by hand.
- **Losses are reported, not hidden.** Every MessageId names its buffer and position, and Postgres
  records how far each buffer was drained. `POST /sesame/v1/runs/{run}/missing` classifies a
  MessageId the client holds but the truth API lacks as `lost` (a simulator loss), `undrained`
  (ask again) or `unknown` (a client finding). `crm:reconcile` reports lost sends under
  `simulator_losses`, apart from CRM mismatches, and exits 2 when the only problem is simulator
  loss. A run with losses is incomplete; rerun it.

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
# Sesame: wakeup and delivery jobs (Cloud's managed queue does this). No scheduler.
php artisan queue:work --queue=default --sleep=0.1 --tries=3
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
| `sesame:run:report` | Simulator-side summary of a run. `--save` stores it on the run row, which outlives raw data. |
| `sesame:prune` | Delete raw data older than 7 days. Runs at most daily after a request; no scheduler. |
| `crm:harness:connect`, `crm:load`, `crm:reconcile` | Local CRM harness only. |

## HTTP surface

| Route | Purpose |
| --- | --- |
| `GET /bfc/meta` | Package metadata, ownership state and verified MCP capabilities. |
| `POST /mcp` | Authenticated read-only accounts, presets, runs, summaries and truth; accepts package MCP credentials or verified Scalpels delegation. |
| `POST /v2/email/outbound-emails` | SES v2 `SendEmail` with `Content.Raw`. The SigV4 access key ID selects the account; signatures are not verified. |
| `GET /sns/{account}/cert.pem` | The account's signing certificate. |
| `GET /sns/{account}/?Action=ConfirmSubscription&...` | The `SubscribeURL`. |
| `GET /sesame/v1/runs/{run}/truth` | Ground truth per `MessageId`, keyset-paginated. `Authorization: Bearer <truth token>`. |
| `GET /sesame/v1/runs/{run}/summary` | Simulator-side metrics for a run. Same token. |
| `POST /sesame/v1/runs/{run}/missing` | Classify MessageIds the truth API lacks: `lost` in an ingest buffer, `undrained`, or `unknown`. Same token. |

## Tests

```bash
php artisan test        # needs Postgres; phpunit.xml uses the sesame_test database
composer stan
```

Every SNS message type is signed by Sesame and verified with the real AWS validator package, so the
signing format cannot drift unnoticed.
