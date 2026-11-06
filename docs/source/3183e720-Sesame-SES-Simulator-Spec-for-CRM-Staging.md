# Sesame: SES Simulator Spec for CRM Staging

Nov 5, 2026 · @Ed Grosvenor

## Goal and scope

CRM staging sleeps until a change needs testing. Waking it starts an automated stress run against a simulated SES, which posts signed SNS notifications back to the CRM's webhook. Each run is compared with earlier runs, so a change that slows sending or notification handling is caught before it ships. The CRM limits itself to 30 sends per second today, so a run holds that rate, then ramps past it to measure headroom.

**In scope for v1:** the parts of the CRM that exist today, the send path and the SNS webhook.

- An SES v2-compatible send API with realistic message IDs, errors and throttling.
- An outcome lottery that produces delivery, bounce, complaint and other events at configurable rates.
- SNS notifications signed in the real format, validated by the same `MessageValidator` code path as production.
- A load driver inside the CRM, plus throughput and correctness measurements.

**Out of scope:** real delivery, rendering checks, inbox placement and deliverability. Those live in other tools.

The simulator is called Sesame. It's built as a standalone Scalpels app so other SES-backed projects can use it. The CRM is the first consumer and the test case.

## Architecture

The load driver sends through the CRM's real send path. The simulator draws an outcome for each message, signs the SNS event, times the webhook's reply and keeps the ground truth that the reconcile job checks against.

&#91;embedded content: staging load loop · CRM and simulator\]

The only things crossing the boundary are the same two calls as production: `SendEmail` going out, and signed SNS POSTs coming back.

## Runtime

Sesame is two parts sharing one Postgres database. One is a standard Laravel Scalpels app that owns reporting, users, config and MCP through `built-for-cloud`. The other is an ingest data plane. A benchmark decides whether the data plane runs on Laravel Octane or Rust; we don't decide upfront.

**First, the Octane benchmark.** Build a stub ingest endpoint (parse, draw, enqueue, return a `MessageId`) on Octane with FrankenPHP, and drive it at about 300 requests a second for 10 minutes. If p99 stays in single-digit milliseconds with no long tail, the data plane is Laravel; otherwise it's Rust. The bar is latency fidelity, not throughput. The simulator's response time is part of what the CRM measures, so any jitter in it pollutes every run.

**How the work splits**

- **Laravel app:** migrations (the schema is the contract), accounts, presets, runs, rollups, dashboard, MCP.
- **Data plane:** `SendEmail` ingest, the outcome draw, the scheduler, signing and delivery, and batched writes of messages, events and metrics. It reads config and never writes it; changes arrive through Postgres `LISTEN/NOTIFY`.

**Either way**

- **Fast acknowledgement.** Verify the request shape, draw the outcome, append to a write-ahead log and return the `MessageId`, all before any database write.
- **A scheduler that survives restarts.** Pending events, roughly two per message, sit in a timing wheel backed by the log, so a restart resumes a run instead of losing it.
- **Signing and delivery.** A fixed worker pool signs envelopes (RSA, SHA256) and POSTs them, timing each attempt.
- **Injected latency.** An optional, configurable latency distribution on `SendEmail`. The simulator is fast by default, and it can be made to answer like SES when the CRM's behaviour under realistic response times is the point.
- **Storage.** Raw messages and events are kept for 7 days. Daily rollups keep only stats, indefinitely, plus a summary row per run, so a run can be compared with any earlier one after its raw data is gone.

**If it's Rust**

- axum on tokio. `sqlx` with compile-time-checked queries against Laravel's migrations, so a migration that breaks ingest fails the Rust build instead of production. Batched `COPY` for writes.
- Split into a generic crate (signature parsing, the write-ahead log, batched `COPY`, the scheduler) and an SES-specific binary, so a shared ingest service is possible later if another tool turns out to need it.
- It installs as the Laravel app on Cloud plus the binary on a Forge server. Confirm early that the Forge server can reach Cloud's Postgres.

## SES API surface

v1 needs one endpoint, because Laravel's `ses-v2` mailer only ever calls `SesV2Client::sendEmail` with `Content.Raw`. That's `POST /v2/email/outbound-emails`. Laravel merges `config('services.ses')` into the client options, so staging only needs `'endpoint' => env('SES_ENDPOINT')` added there.

**Request handling**

- Accept any SigV4-signed request. The access key ID selects a *simulated account* (its preset, rate limit, seed and webhook), so several projects can share one instance.
- Read `Destination.ToAddresses`, `ConfigurationSetName`, `EmailTags` and `TenantName`. Laravel turns a Mailable's `metadata()` into `EmailTags`, which the simulator echoes back in every event, so the CRM can tag sends with its own IDs.
- Laravel also sends a v1-style `Source` key alongside v2 parameters. Tolerate it.
- Parse only the raw MIME headers (`From`, `To`, `Subject`, `Message-ID`, `Date`), not the body. The headers fill `mail.commonHeaders` in events. Skipping body parsing keeps the ingest path fast.
- Return `{"MessageId": "…"}` in SES's shape: a hex timestamp prefix, a UUID and a `-000000` suffix. The CRM stores it and correlates every later event by it.

**Errors the simulator can return** (all configurable, all off by default except throttling)

| Error | HTTP | When |
| --- | --- | --- |
| `TooManyRequestsException` | 429 | Over the simulated account's max send rate, set to mirror the client's real SES quota |
| `MessageRejected` | 400 | The lottery draws Reject at send time |
| `BadRequestException` | 400 | Malformed address or a missing recipient |
| `SendingPausedException` | 400 | The `paused` preset, or simulated reputation crossing SES's pause thresholds |

Later, not v1: `SendBulkEmail`, templates, `GetAccount` (for quota display), the v1 `SendRawEmail` endpoint and the SMTP interface.

## Outcome lottery

The simulator draws one outcome per recipient at send time, using a seeded RNG, then schedules the matching SES events. Presets set the rates; any rate can be overridden per simulated account.

**Presets.** These are anchored on SES's real enforcement lines. Hard bounces: review at 5%, possible pause at 10%. Complaints: review at 0.1%, possible pause at 0.5% ([AWS](https://docs.aws.amazon.com/ses/latest/dg/reputationdashboardmessages.html)). SES counts only hard bounces toward the bounce rate. The `healthy` numbers are our own assumed defaults, not published benchmarks.

| Outcome (per recipient) | healthy | review | paused | chaos |
| --- | --- | --- | --- | --- |
| Hard bounce (Permanent) | 0.8% | 5.5% | 11% | 3% |
| Soft bounce (Transient) | 1.0% | 2% | 3% | 5% |
| Complaint | 0.05% | 0.12% | 0.6% | 0.3% |
| DeliveryDelay, then delivered or bounced | 0.5% | 1% | 2% | 5% |
| Reject at send | 0.01% | 0.01% | 0.01% | 1% |
| Delivered | rest | rest | rest | rest |
| Duplicate notification (SNS at-least-once) | 0.2% | 0.2% | 0.2% | 3% |
| Out-of-order delivery of events | 0.5% | 0.5% | 0.5% | 5% |

`chaos` exists to test how robust the CRM is, not to look realistic. In `paused`, once simulated reputation crosses the line, sends start returning `SendingPausedException`.

**Event detail drawn per outcome**

- **Bounces** use SES's real subtypes, weighted. Permanent: `General`, `NoEmail`, `Suppressed`, `OnAccountSuppressionList`. Transient: `General`, `MailboxFull`, `MessageTooLarge`, `ContentRejected`, `AttachmentRejected`.
- **Complaints** carry a `complaintFeedbackType`, usually `abuse`, sometimes absent (SES only includes it when the ISP attaches a feedback report).
- **DeliveryDelay** uses `delayType` values such as `MailboxFull`, `RecipientServerError` and `TransientCommunicationFailure`. It's followed by a final Delivery or Bounce.
- **Open and Click** (later, not v1) are drawn as fractions of delivered messages.

**Timing.** Compressed so feedback arrives while the load runs. Send: immediately. Delivery: 1–5 s. Bounce: 1–30 s. Complaint: 30 s–10 min. Delayed messages: their final event 1–15 min later. All configurable.

**Memory.** An address that hard-bounced or complained goes on the simulated account's suppression list. Any later send to it bounces with `OnAccountSuppressionList`, and the simulator counts these, because they're exactly the sends the CRM should have stopped making.

**Determinism.** A recipient local part can force an outcome, mirroring SES's simulator: `bounce+anything@`, `complaint+anything@`, `delay+anything@`, `success+anything@`. The seed is recorded per run, so a failing run can be replayed exactly.

## SNS notifications

The simulator acts as SNS too: it POSTs SNS envelopes straight to the CRM's webhook, signed with its own key, in a format `aws/aws-php-sns-message-validator` accepts once it's bound to the simulator's certificate host.

**Payload.** Use the event-publishing format (`eventType`, via a configuration set), not the older identity-notification format (`notificationType`). It's the only one with `Send`, `Reject`, `DeliveryDelay`, `Open` and `Click`. The CRM is new, so it should be built against this format from day one. `mail` carries `timestamp`, `messageId`, `source`, `destination`, `headers`, `commonHeaders` and `tags`, matching [SES's event contents](https://docs.aws.amazon.com/ses/latest/dg/event-publishing-retrieving-sns-contents.html).

**Envelope**

- A JSON body with `Type`, `MessageId`, `TopicArn`, `Message` (the SES event as a string), `Timestamp`, `SignatureVersion: "2"`, `Signature`, `SigningCertURL` and `UnsubscribeURL`.
- Headers: `x-amz-sns-message-type`, `x-amz-sns-message-id`, `x-amz-sns-topic-arn`, `x-amz-sns-subscription-arn`. The validator's `Message::fromRawPostData()` throws if the type header is missing.
- `Content-Type: text/plain; charset=UTF-8`, which is SNS's default. A webhook that expects `application/json` and reads `$request->json()` gets nothing, which is a classic first-deploy bug this catches.

**Signing.** The simulator generates an RSA key pair per simulated account and serves the self-signed certificate at `https://<sim-host>/sns/<account>/cert.pem`. The URL must be HTTPS and end in `.pem`, or the validator rejects it. Signature version 2 means SHA256 over the validator's string-to-sign. For notifications that's `Message`, `MessageId`, `Subject` (if present), `Timestamp`, `TopicArn` and `Type`. The confirmation types add `SubscribeURL` and `Token`. The simulator's own test suite verifies every message type with the real validator package, so the format can't drift.

**Subscription lifecycle.** Registering a webhook triggers a `SubscriptionConfirmation`. Nothing is delivered until the CRM GETs the `SubscribeURL`, so the CRM's confirmation handling gets exercised too. `UnsubscribeConfirmation` comes later.

**Delivery and retries.** These mirror SNS's HTTP rules ([AWS](https://docs.aws.amazon.com/sns/latest/dg/sns-message-delivery-retries.html)):

- Only 5xx and 429 are retried. Any other error is a permanent failure and the message is dropped. A webhook that answers 403 on a bad signature silently loses events, and the simulator reports every drop.
- The default policy, when one is set without specifics, is 3 retries, 20 s apart, linear. Custom policies follow SNS's four phases, capped at 3,600 s total.
- An optional `throttlePolicy.maxReceivesPerSecond` limits delivery to the webhook, as SNS can.

The simulator times every POST from send to 2xx and keeps that latency per event. It's the most direct measure of the webhook's throughput.

## CRM-side wiring

Staging and production run the same code. They differ only in three environment values, and production refuses to boot if those values point at the simulator.

**Config**

```php
// config/services.php
'ses' => [
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'eu-central-1'),
    'endpoint' => env('SES_ENDPOINT'),          // staging: https://<sim-host>
],
'sns' => [
    'cert_host_pattern' => env('SNS_CERT_HOST_PATTERN'), // staging: /^<sim-host>$/
],
```

The mailer is `ses-v2`, with `ConfigurationSetName` set under the mailer's `options`.

**Validator binding.** Resolve `MessageValidator` from the container everywhere and never call `new` on it, so the host pattern can be swapped:

```php
$this->app->singleton(MessageValidator::class, fn () => new MessageValidator(
    hostNamePattern: config('services.sns.cert_host_pattern') ?? '',
));

// AppServiceProvider::boot()
if (app()->isProduction() && (config('services.ses.endpoint') || config('services.sns.cert_host_pattern'))) {
    throw new RuntimeException('SES simulator config present in production.');
}
```

An empty string falls back to the validator's own `amazonaws.com` pattern.

**Sending**

- Store the `MessageId` SES returns on the outbound message row (the transport also adds it as `X-SES-Message-ID`). Every event correlates by `mail.messageId`, never by recipient address.
- Put the CRM's own IDs (message, contact, campaign) in Mailable `metadata()`. They come back as `mail.tags` on every event.
- Treat `TooManyRequestsException` as back-pressure. Release the job with backoff; don't fail it.

**Webhook**

- Build the `Message` from the raw request body, which arrives as `text/plain`. Validate it, persist the raw envelope, return 200, and do the rest in a queued job. The webhook's response time is what the simulator measures.
- Return 5xx only for failures worth retrying, such as a database outage. Return 200 for valid messages you choose to ignore. Answer 4xx only for invalid signatures, and alert on them: SNS doesn't retry 4xx, so those events are gone.
- Make processing idempotent on the SNS `MessageId`, with a unique index. Make state changes order-tolerant: a `Delivery` arriving before its `Send`, or a duplicate `Bounce`, must not corrupt a contact.
- Handle `SubscriptionConfirmation` by GETting `SubscribeURL` after validating the message.

**Load contacts.** Synthetic contacts use a reserved domain, for example `@load.crm.test`, created by a seeder that refuses to run in production. That keeps load data out of real reports and gives a second guard if the endpoint config is ever wrong.

## Load and throughput measurement

A run counts as a regression if any gated metric is worse than its baseline by more than the tolerance. The baseline is the median of the last five comparable runs, meaning the same profile, preset and seed, read from the per-run summaries. The simulator holds the ground truth, so a run checks correctness as well as speed.

**A run, end to end.** A change lands on the staging branch. CI wakes staging and deploys. It waits for health checks and SNS subscription confirmation, then starts `php artisan crm:load --profile=stress`. That command dispatches sends through the CRM's real send path (queue, Mailable, transport). When the run ends, a drain period lets late complaints arrive. Then the CRM reconciles, the report posts to the PR, and staging goes back to sleep.

**Stress profile.** First a 15-minute plateau at the CRM's configured rate, 30/s today, which is the spec check. Then steps of 30, 45, 60 and 90/s, 5 minutes each, until a gate trips. The highest step that passes every gate is the run's **ceiling**. A plateau that passes while the ceiling drops means the change ate headroom the client will need as their volume grows.

**Metrics**

| Metric | Measured by | Gate (initial guess, tune after a few runs) |
| --- | --- | --- |
| Ceiling (highest step passing all gates) | Simulator | one step below baseline |
| Achieved send rate vs target, on the plateau | Simulator | below 95% of target for 2 min |
| Send latency p95 (job dispatched → SES accepted) | CRM | +25% vs baseline |
| Queue lag (oldest pending send) | CRM | above 30 s on the plateau |
| `TooManyRequestsException` rate | Simulator | above 1% of sends on the plateau |
| Webhook response p95 (POST → 2xx) | Simulator | +25% vs baseline, or above 1 s |
| Webhook 5xx and timeouts | Simulator | above 0.1% |
| Webhook 4xx (events lost) | Simulator | any |
| Event lag p95 (webhook received → CRM state updated) | CRM | +25% vs baseline |
| Sends to suppressed addresses | Simulator | any, after a 5 min grace period |
| Reconciliation mismatches | Simulator vs CRM | any |

**Reconciliation.** After the drain period, the simulator exposes the ground truth per `MessageId`: the outcome drawn and the events delivered. A CRM job compares it with its own state: contact suppressed, message marked bounced, complaint recorded. Throughput can stay flat while correctness breaks. A refactor that drops out-of-order events, for example, only shows up here.

**Where it surfaces.** Each run writes a summary row (every metric, the ceiling and the verdict) that outlives the 7-day raw data. The verdict and the diff against the baseline post to the PR that triggered the run. CRM-side metrics come from Nightwatch telemetry, which Hone can query. Agent access through MCP depends on the control-plane decision in Runtime.

## Build plan and open questions

Phase 1 is the smallest loop that completes one stress run end to end. Everything after it adds realism or reporting.

1. **Loop closed.** The Rust service: `SendEmail` (Raw), message IDs, the `healthy` preset with Delivery, Bounce and Complaint, a timing wheel, signed SNS POSTs, the certificate endpoint, subscription confirmation, 7-day raw storage. On the CRM side: config, validator binding, webhook, `crm:load`. *Done when:* a 30/s plateau completes with every event reconciled.
2. **Measurement.** The stress profile with ramp steps and ceiling, per-event webhook timing, the reconciliation API, per-run summaries and daily rollups, baselines and gates, the PR report, the wake, run and sleep flow in CI. *Done when:* a deliberately slowed webhook (an added `sleep`) fails the gate and lowers the ceiling.
3. **Realism.** Transient bounce subtypes, DeliveryDelay, Reject, suppression memory, duplicates and out-of-order events, throttling, injected `SendEmail` latency, the `review`, `paused` and `chaos` presets, forced outcomes by local part, replay by seed.
4. **Scalpels packaging.** The control plane and MCP access (decided separately), multiple simulated accounts per instance, a dashboard, README instructions for running it without Scalpels, then Open and Click, `SendRawEmail` v1 and SMTP.

**Open questions**

- **Control plane and MCP.** How the Rust data plane gets agent access, given that the consolidated Scalpels MCP runs on `built-for-cloud`. To be discussed separately.
- **Hosting the Rust service.** Where it runs, and whether it can sleep and wake with staging or stays up for every project that uses it.
- **Waking staging.** What wakes the sleeping environment and triggers the run: a CI job on the staging branch, or a manual MCP call for ad-hoc runs.
- **Ramp steps.** Are 30, 45, 60 and 90/s the right steps for this client's growth, and should the top step track the client's real SES quota?
