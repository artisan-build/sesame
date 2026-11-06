# Sesame proof of concept: report

6 November 2026. Source specification: Ed Grosvenor's brief of 5 November,
[`docs/source/3183e720-Sesame-SES-Simulator-Spec-for-CRM-Staging.md`](source/3183e720-Sesame-SES-Simulator-Spec-for-CRM-Staging.md)
(SHA-256 `0d08d01cb8adc8d8bd46194f12b8dd7d69502248d3718f3af14a91436d57b1b6`).

The goal was the brief's Phase 1, "loop closed": one stress plateau end to end, done when a 30/s
plateau completes with every event reconciled. It also had to answer the Runtime section's
Octane-versus-Rust benchmark first. Everything ran in one Amp orb (2 vCPU, 4 GB, Debian 12,
PHP 8.4 CLI, FrankenPHP 1.13.5 with PHP 8.5, Postgres 15). Nothing touched Growth staging, Laravel
Cloud, AWS, or real email.

## Summary

| Question | Answer | Verdict |
| --- | --- | --- |
| Does the loop close at 30/s with every event reconciled? | Yes, on the local harness. 27,000 sends over a 15-minute plateau, {{EVENTS}} SNS notifications, {{MISMATCHES}} reconciliation mismatches. | Demonstrated locally, not against Growth staging |
| Does the Octane ingest hold single-digit p99 at 300/s for 10 minutes? | Not on this host, but a no-PHP control failed the same way. In quiet seconds Octane's p99 was 9.83 ms. | **Not decidable here.** Laravel is provisional; rerun on the target host |
| Are notifications signed in a format the real validator accepts? | Yes. All three SNS types are verified with `aws/aws-php-sns-message-validator` in tests, and live over HTTPS during the plateau. | Test-verified and live in the orb |
| Does a restart resume a run? | Yes. WAL records are ingested exactly once, and pending events are rebuilt from Postgres. | Test-verified; not exercised under live load |

## What was built

**Sesame** (`app/Sesame`), one Laravel 13 app on Postgres:

- **SES v2 `SendEmail` (`Content.Raw`)** at `POST /v2/email/outbound-emails`. The SigV4 access key
  ID selects a simulated account; signatures are accepted, not verified, as the brief asks. It reads
  `Destination`, `ConfigurationSetName`, `EmailTags`, `TenantName` and tolerates Laravel's v1-style
  `Source`. Only the MIME header block is decoded, never the body. MessageIds have SES's shape
  (`0100` plus a hex timestamp, a UUID, `-000000`). Errors use the REST-JSON `x-amzn-ErrorType`
  shape the AWS SDK maps: `TooManyRequestsException` (per-account one-second windows, shared across
  FrankenPHP threads through APCu), `MessageRejected`, `BadRequestException`, and
  `SendingPausedException` for the `paused` preset.
- **Fast acknowledgement.** Verify, draw, plan the events, append one fsynced line to a write-ahead
  log, return the `MessageId`. A test asserts no database write happens on this path. Account
  config is read from a snapshot file the data plane rewrites on Postgres `NOTIFY`.
- **Outcome lottery** with the brief's four presets, per-account rate and timing overrides, weighted
  SES bounce subtypes, complaint feedback type that is sometimes absent, DeliveryDelay followed by
  Delivery or Bounce, duplicate notifications (same SNS MessageId), out-of-order delivery (Send
  after the final event), forced outcomes by local part (`bounce+`, `complaint+`, `delay+`,
  `success+`), and a seed recorded per run. The draw is a pure function of seed and recipient, so a
  run replays exactly with `sesame:run:start --seed`.
- **Data plane** (`sesame:dataplane`): WAL ingest with batched `COPY`, with the WAL checkpoint
  committed in the same transaction as the rows; a 100 ms hashed timing wheel rebuilt from pending
  rows on start; `LISTEN sesame_config` for config changes; SNS subscription confirmation; and a
  fixed pool of 32 concurrent curl-multi POSTs, each timed from send to response.
- **SNS**: event-publishing payloads (`eventType`, `mail` with `headers`, `commonHeaders`, `tags`),
  envelopes with `SignatureVersion: "2"` (RSA-SHA256 over the validator's string-to-sign), the
  four `x-amz-sns-*` headers, `Content-Type: text/plain; charset=UTF-8`, a per-account RSA key with
  a self-signed certificate at `https://<host>/sns/<account>/cert.pem`, and the SubscribeURL.
  Nothing is delivered until the webhook GETs the SubscribeURL.
- **SNS retry rules**: only 5xx, 429 and transport failures are retried, by default 3 times, 20 s
  apart. Anything else is dropped and reported, because SNS drops it too.
- **Ground truth**: `GET /sesame/v1/runs/{run}/truth` (per `MessageId`, outcome drawn, every event
  with its delivery status and webhook latency) and `/summary`, behind a per-account bearer token
  stored as a SHA-256 hash.
- **Storage**: `sesame:prune` deletes raw data after 7 days and is scheduled daily;
  `sesame:run:report --save` stores a run summary on the run row, which outlives raw data.

**Local CRM-compatible harness** (`app/CrmHarness`). This is clearly not Growth. It implements the
brief's "CRM-side wiring" so the loop can close in one checkout:

- `services.ses.endpoint`, `services.sns.cert_host_pattern`, a container-bound `MessageValidator`,
  and the production boot guard.
- The stock Laravel `ses-v2` mailer with SDK retries off. A queued job sends a ~24 KB personalised
  Mailable whose `metadata()` carries the CRM IDs, stores the returned MessageId, and treats
  `TooManyRequestsException` as back-pressure (release with backoff).
- A webhook that builds the `Message` from the raw `text/plain` body, validates it, persists the
  envelope, answers 200 and processes in a queued job. It answers 4xx only for invalid signatures
  and 5xx only for storage failures, is idempotent on the SNS MessageId through a unique index, and
  GETs the SubscribeURL.
- Order-tolerant state. A message's state is recomputed from every event received, so a Delivery
  before its Send, or a duplicate Bounce, cannot corrupt it. Suppression is only ever added.
- Synthetic contacts on `@load.crm.test`, from a seeder that refuses to run in production. A
  second guard refuses to send outside that domain.
- `crm:load` (a paced plateau) and `crm:reconcile` (compares Sesame's ground truth with CRM state:
  every event received, message state, suppression, DeliveryDelay recorded).

## Live 30/s plateau

Real processes over real HTTP in the orb:

- Sesame Octane (FrankenPHP, 4 workers) on `https://sesame.localhost:8443` with Caddy's internal CA.
- `sesame:dataplane`.
- The harness on its own Octane server at `http://127.0.0.1:8001`.
- 4 send workers and 2 event workers on the database queue.

Account `growth-staging`, preset `healthy`, seed `20261106`, timing as in the brief: Delivery
1–5 s, Bounce 1–30 s, Complaint 30 s–10 min, delayed finals 1–15 min later.

Commands, exactly as run:

```bash
php artisan sesame:account:create growth-staging --webhook=http://127.0.0.1:8001/crm-harness/sns --max-rate=200 --seed=20261106 --json
php artisan crm:harness:connect creds.json
php artisan crm:load --rate=30 --duration=10 --run=smoke               # smoke, on an earlier run
php artisan sesame:run:start growth-staging --seed=20261106 --label=plateau-30x900
php artisan crm:load --rate=30 --duration=900 --run=plateau-30x900
php artisan crm:reconcile --run=plateau-30x900 --wait=1500 --json=reconcile.json
```

Results ([`evidence/plateau-reconcile.json`](evidence/plateau-reconcile.json)):

| Metric | Result | Brief's gate |
| --- | --- | --- |
| Sends dispatched / accepted | 27,000 in 900.0 s / 27,000 | |
| Achieved rate at Sesame, per second | mean 29.97, p1 27, p99 33, min 14, max 44, no empty seconds | |
| Worst rolling 2-minute achieved rate | 29.86/s (99.5% of target) | below 95% for 2 min |
| `TooManyRequestsException` | 0 | above 1% |
| Send latency p50 / p95 (dispatched to accepted, CRM side) | {{SEND_P50}} / {{SEND_P95}} ms | +25% vs baseline |
| Max queue lag | {{QUEUE_LAG}} s | above 30 s |
| Webhook response p50 / p95 / p99 / max | {{WH_P50}} / {{WH_P95}} / {{WH_P99}} / {{WH_MAX}} ms | +25%, or above 1 s |
| Webhook 5xx and timeouts | {{WH_5XX}} | above 0.1% |
| Webhook 4xx (events lost) | {{WH_4XX}} | any |
| Event lag p50 / p95 (received to CRM state) | {{EV_P50}} / {{EV_P95}} ms | +25% vs baseline |
| Outcomes drawn | {{OUTCOMES}} | |
| Events delivered / dropped / pending after drain | {{EV_DELIVERED}} / {{EV_DROPPED}} / {{EV_PENDING}} | |
| **Reconciliation mismatches** | **{{MISMATCHES}}** of {{COMPARED}} messages compared | any |

Short dips to 14–24/s, each followed by a 36–44/s catch-up second, line up with the orb stalls
described under the benchmark. `crm:load` paces by wall clock, so it catches up after a stall.

## Runtime benchmark

The brief says: build a stub ingest endpoint on Octane with FrankenPHP, drive it at about 300
requests a second for 10 minutes, and choose Laravel if p99 stays in single-digit milliseconds with
no long tail, Rust otherwise. The bar is latency fidelity. Phase 1's wording ("The Rust service")
predates that decision and was not followed blindly.

**Setup.** The real ingest endpoint, not a throwaway stub: parse, header-only MIME read, draw,
event plan, one fsynced WAL append, return the `MessageId`. 1,000 distinct ~24 KB SendEmail bodies
shaped like Laravel's `ses-v2` mailer. vegeta 12.12 at a constant 300/s for 600 s from the same
2-vCPU orb, Octane with 4 workers. A control ran alongside: a Caddy route answering a static 200
with no PHP, at 100/s. Its tail is the host's tail. Method: [`bench/README.md`](../bench/README.md).

| Run | Requests | Octane p50 | p95 | p99 | p99.9 | max | Control p99 | Control max |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 (08:56 UTC) | 180,000, all 200 | 4.44 ms | 560 | 925 | 1,532 | 1,623 | 1,129 | 1,661 |
| 2 (09:17 UTC) | 180,000, all 200 | 3.95 ms | 8.86 | 28.0 | 178.5 | 268.5 | 27.6 | 262.9 |
| 2, quiet seconds only (109 s) | 32,701 | 3.71 ms | 6.26 | **9.83** | 21.5 | 45.8 | under 10 by definition | |

"Quiet seconds" are the seconds in which every control request finished under 10 ms. Per-minute
breakdowns: [`evidence/benchmark-run-1.json`](evidence/benchmark-run-1.json),
[`evidence/benchmark-run-2.json`](evidence/benchmark-run-2.json).

**Reading.** Read literally, the bar fails: run 2's p99 is 28 ms. But the static control, which
runs no PHP at all, has the same distribution (p99 27.6 ms, p99.9 180 ms), with stalls at the same
seconds. Octane adds nothing measurable over a static Caddy response on this host. In the seconds
where the host was quiet, Octane's p99 was 9.83 ms, just inside single digits, with about 1% of
requests between 10 and 46 ms.

**Verdict: not decidable on this orb.** The failure is the host's, so it is not evidence for Rust,
and 9.83 ms in quiet seconds is too thin to certify Laravel. The PoC data plane is PHP/Laravel,
provisionally. Before committing either way, rerun on the host Sesame will actually use, with the
load generator on another machine and the control alongside. The bench files make that a
copy-paste job.

**Conditions and limitations, honestly:**

- Shared 2-vCPU orb with the load generator on the same CPUs; CPU was about 80% busy at the median.
- The orb was intermittently suspended or stalled. The executor lost its lease several times, and
  the orb clock advanced less than wall time. Run 1 is dominated by multi-second stalls that hit the
  control equally.
- One early cause was found and fixed: Amp's workspace scan hashing the growing WAL file pegged
  both vCPUs. `storage/sesame/` is now git-ignored.
- HTTP/1.1 on loopback, no TLS, no network. Real staging adds TLS and network latency.
- The data plane did not run during the benchmark (WAL appends only). In production, ingest and
  the data plane share a host.

## Tests

`php artisan test`: 83 passed, of which 31 are Sesame and harness tests; the rest are the
starter's auth tests, on Postgres. PHPStan level 6: 0 errors. Pint: clean.

| Test file | Proves |
| --- | --- |
| `tests/Unit/Sesame/OutcomeLotteryTest.php` | Same seed replays, different seed differs; rates near configured over 20,000 draws; forced local parts (and `bouncer@` is not forced); timing windows respected |
| `tests/Feature/Sesame/SnsSigningTest.php` | Notification (with and without Subject), SubscriptionConfirmation and UnsubscribeConfirmation all verify with the real `MessageValidator`; a tampered field, another account's key, or a validator still bound to `amazonaws.com` all fail; the cert is served at `.pem` |
| `tests/Feature/Sesame/SendEmailTest.php` | Real AWS SDK `SesV2Client` against the endpoint: SES-shaped MessageId, tags and headers captured, folded headers unfolded, body ignored; no DB write on ingest; SDK-mapped error codes; throttling; Reject |
| `tests/Feature/Sesame/LoopTest.php` | The whole loop in process: harness job, Mailable, ses-v2 transport, ingest, WAL, data plane, signed POST, webhook, queued processing, reconcile. Confirmation gates delivery; 1,000 messages with 30% duplicates and 30% out-of-order reconcile with 0 mismatches; a webhook answering 403 loses events and reconcile fails; 5xx retries 3 times then drops; a restart re-reads the WAL exactly once and delivers rescheduled events; accepted work is not resent; suppressed contacts are skipped |
| `tests/Feature/CrmHarness/SafetyTest.php` | Production boot refuses either simulator setting; staging boots; the load seeder refuses production; sends outside the load domain are refused; 403 for a missing type header or bad signature, 200 and a single record for a duplicate |

Writing the tests surfaced three real bugs, now fixed:

- The migration was not re-runnable after `migrate:fresh`.
- The `COPY` null marker was mangled by PDO's `E''` quoting.
- The account snapshot cache was keyed on one-second `filemtime`, which could serve a previous
  run's seed. It is now keyed on inode plus mtime.

## What is not done

Against the brief, in order of how much it matters next:

1. **Growth staging integration.** Not attempted: no credentials, and not authorized. Growth at
   `4402f1e` needs these changes before it can use Sesame:
   - `TrialFeedbackAuthentication` pins `sns.us-east-1.amazonaws.com` in the validator and in a
     SigningCertURL regex. Both need to come from `services.sns.cert_host_pattern`.
   - It confirms subscriptions with `SnsClient::confirmSubscription` against the fixed AWS endpoint.
     Sesame serves the GET `SubscribeURL`, not the SNS query API, so Growth needs to GET the
     validated SubscribeURL (as the brief says) or Sesame needs a `ConfirmSubscription` action.
   - `TrialEligibility` only allows `…@simulator.amazonses.com`, while the brief's load contacts
     use `@load.crm.test`. Sesame accepts either. Its forced outcomes match on the local part, so
     Growth's `success+`/`bounce+`/`complaint+` simulator addresses already work, but `ooto+` does
     not.
   - The topic ARN pin must accept Sesame's ARN.
   - Setting `services.ses.endpoint` is enough for the existing `growth-ses` mailer.
2. **Runtime decision**: rerun the benchmark on the target host (above).
3. **Hosting**: the WAL is a local file shared by the Octane ingest and the data plane, so they must
   run on one host with a persistent disk. Laravel Cloud's ephemeral filesystem would lose
   un-ingested records on redeploy. A Forge server, or moving the WAL, is needed. That ties into the
   brief's open question on hosting.
4. **Phase 2 (measurement)**: ramp steps and ceiling, gates and baselines, daily rollups, the PR
   report, the CI wake/run/sleep flow. Per-event webhook timing and the reconciliation API exist.
5. **Phase 3 (realism) gaps**: suppression memory (`OnAccountSuppressionList` and the "sends to
   suppressed addresses" metric), reputation-driven `SendingPausedException`, injected `SendEmail`
   latency, custom retry policies and `throttlePolicy.maxReceivesPerSecond`,
   `UnsubscribeConfirmation`.
6. **Phase 4 / control plane**: `built-for-cloud` users, dashboard and MCP are not installed. The
   accounts are Artisan-only. Multiple simulated accounts per instance work.
7. **Signing throughput**: signing happens in the single data-plane loop, not a worker pool. That
   was fine at 30/s (about 75 notifications/s) but was not measured beyond that.

## Operating notes

- Sesame needs HTTPS for its certificate URL. Locally, `octane:frankenphp --https` with
  `SESAME_CA_BUNDLE` pointing consumers at Caddy's root CA.
- A second Octane server from the same checkout needs its own `OCTANE_STATE_FILE`.
- Keep `storage/sesame/` out of anything that scans the tree.
