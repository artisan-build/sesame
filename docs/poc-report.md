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
| Does the loop close at 30/s with every event reconciled? | Yes, on the local harness. 27,000 sends over a 15-minute plateau, 54,252 SNS notifications, 0 reconciliation mismatches. | Demonstrated locally, not against Growth staging |
| Does the Octane ingest hold single-digit p99 at 300/s for 10 minutes? | Not on this host, but a no-PHP control failed the same way. In quiet seconds Octane's p99 was 9.83 ms. | **Not decidable here.** Laravel is provisional; rerun on the target host |
| Are notifications signed in a format the real validator accepts? | Yes. All three SNS types are verified with `aws/aws-php-sns-message-validator` in tests, and live over HTTPS during the plateau. | Test-verified and live in the orb |
| Does a restart resume a run? | Delivery resumes: a lost or crashed delivery job's events go out again when their lease runs out, exactly once (live, after a real worker failure). Sends acknowledged but not yet drained from a replaced instance's buffer are **lost by design** and reported apart from CRM findings. | Test-verified and live in the orb; not on Laravel Cloud |
| Does it fit Laravel Cloud's managed resources? | Yes, by design: web app, Postgres, managed queue. No scheduler (so it can hibernate), persistent disk or long-running process. | **Untested on Cloud**: no live provisioning run was authorized |

## Cloud-native redesign (6 November, later)

The first PoC kept a write-ahead log on local disk and ran a long-lived `sesame:dataplane`
process. Laravel Cloud offers neither, and a Scalpels install has to run on what it provisions.
Sesame now uses only Cloud resources that Scalpels already provisions: the web app (PHP-FPM or
Octane), Postgres and a managed queue. Since the scheduler-free change below it also has no
scheduler, so an idle install can hibernate.

```
SendEmail ──► SQLite buffer in the instance's tmp dir ──► 200 + MessageId
                     │ after the response of ANY request on this instance (Housekeeping):
                     │ drain every row it can claim, bounded batches, one drain per buffer (flock)
                     ▼
               Postgres: sesame_messages, sesame_events, sesame_buffers
                     │ the drain queues one delayed SweepDue per whole second that has events due
                     ▼
               managed queue: SweepDue (delayed; > 15 min re-queues itself) ── leases due events
                     │ (FOR UPDATE SKIP LOCKED) and queues a wakeup for the lease's expiry
                     ▼
               managed queue: DeliverEvents (25 events, concurrent POSTs, tries=1)
                     │ settles only rows still holding its lease token; a retry queues its wakeup
                     ▼
               signed SNS POST ──► CRM webhook (fetching cert.pem wakes the web app)
```

### Scheduler-free delivery, so Sesame can sleep (6 November, later still)

Sesame is used occasionally, so Ed chose option B: no scheduler at all. On Laravel Cloud a
scheduled task wakes a hibernating environment and keeps it up for the whole sleep timeout, so the
earlier every-second drain and sweep meant it never slept. What replaced them:

- **Draining** rides on requests. `App\Sesame\Http\Housekeeping` is global middleware that
  defers `IngestBuffer::drain()` after every response. The drain takes every buffered row it can
  claim, not just its own request's: batches of `sesame.buffer.drain_batch` (500) until the buffer
  is empty or `sesame.buffer.drain_seconds` (5 s) pass, well under a response worker's limits.
  One drain owns a buffer at a time through an exclusive `flock`; a request that finds it taken
  returns at once, and the owner looks again after releasing, so no row is stranded by losing
  that race.
- **Delivery timing** rides on delayed managed-queue jobs. After a drain commits, `Wakeups` queues
  one delayed `SweepDue` per whole second that has events due (deduplicated through the cache).
  The queue's longest delay is 15 minutes; a later wakeup arrives early and re-queues itself
  until its time, so a complaint due an hour out is a short chain of jobs. The managed queue runs
  on its own compute and wakes for delayed jobs without waking the web app.
- **Recovery** has two lines. Every sweep that leases events queues a wakeup for the lease's
  expiry, so a lost `DeliverEvents` job only delays its events; a 5xx/429 retry queues a wakeup
  for its due time. Separately, at most every `sesame.housekeeping_seconds` (10 s) per instance a
  request's after-response work heartbeats the buffer and sweeps anything overdue, which covers
  a lost wakeup.
- **Pruning** runs at most daily, after a request, instead of on a schedule.

**Live run with no scheduler (orb, real processes, 6 November).** Sesame on Octane (4 workers,
HTTPS), 2 `queue:work` processes on the database queue standing in for the managed queue, the
harness on its own Octane server, and **no `schedule:work` and no scheduled task registered**.
`SESAME_MAX_DELAY_SECONDS=120`, so any wakeup further out than 2 minutes had to chain through
re-queued `SweepDue` jobs, as a 15-minute SQS delay would. 30/s for 180 s, run `b-plateau`,
healthy preset with default timing (complaints up to 10 minutes, delayed finals up to 15 minutes
out). Evidence: [`evidence/scheduler-free-plateau-reconcile.json`](evidence/scheduler-free-plateau-reconcile.json).

- 5,400 dispatched: 5,399 accepted and 1 `MessageRejected` (the preset's 0.01% reject rate).
- `crm:reconcile` exit 0: 5,399 messages compared, **0 mismatches**, 0 lost, 0 undrained.
- 10,854 events, **all delivered** at the first attempt, 0 dropped, 0 failed jobs.
- Lag from due time to first POST, by type, p50 / p99 / max: Send 623 / 1,073 / 1,155 ms,
  Delivery 596 / 1,053 / 1,128 ms, Complaint 491 / 965 / 991 ms, Bounce 542 / 1,002 / 1,079 ms.
  That is the whole-second wakeup granularity plus queue polling, and it held for finals 15
  minutes out that reached their time through chained wakeups.
- Afterwards the queue held **0 jobs**, nothing was scheduled, and nothing ran: an idle install
  has nothing that would keep it awake.

**Delivery workers SIGKILLed repeatedly, still no scheduler (orb, fresh database).** Same stack.
30/s for 90 s, run `b-kill`, with complaint and delayed-final timing compressed to at most 60 and
90 s. Ten times, 6 to 7 s apart, every delivery worker was killed with SIGKILL and restarted
about a second later. Evidence: [`evidence/scheduler-free-worker-kill-reconcile.json`](evidence/scheduler-free-worker-kill-reconcile.json)
and [`evidence/scheduler-free-worker-kill-events.log`](evidence/scheduler-free-worker-kill-events.log).

- Kills left up to 241 events leased to dead jobs. Only the wakeups those leases queued for their
  own expiry could recover them.
- `crm:reconcile` exit 0: 2,699 messages compared, **0 mismatches**, 0 lost; 5,414 of 5,414 events
  delivered, 0 dropped.
- 241 events went out 50 to 61.4 s late, which is the 60 s lease plus one wakeup. 13 killed
  `DeliverEvents` jobs ended in `failed_jobs` (re-handed out by the queue, over `tries=1`), as
  designed: the lease, not the queue, retries. No `SweepDue` failed.
- A killed job may already have posted some of its events, so those can arrive twice. That is
  SNS's own at-least-once behaviour; the harness deduplicates on the SNS MessageId.

An earlier attempt at this run lost 4 accepted sends. The cause was the test suite, not Sesame:
`composer ready` ran alongside it, and non-Sesame feature tests, whose requests now drain after
every response, drained the live server's shared default tmp buffer into the test database and
rolled back. Reproduced with a single unrelated test; `phpunit.xml` now gives tests their own
buffer path. It is a local-machine hazard only: Cloud instances never share a tmp directory with
a test run.

What this costs, plainly:

- **Nothing drains an instance that stops getting requests.** If the after-response drain of the
  last request before traffic stops fails (Postgres unreachable, process killed inside the
  deferred callback), those sends sit in the buffer until the next request reaches that instance.
  `/missing` reports them `undrained` for 30 s and `lost` after that, until a later request drains
  them. With the scheduler a failed drain was retried within a second.
- **An overdue event whose wakeup was lost waits for a lease-expiry wakeup or the next request.**
  If neither comes (no traffic, no leases), it waits until something calls Sesame. A test run
  always ends with the CRM fetching truth from Sesame, and that request sweeps.
- Delivery timing has whole-second granularity plus the queue's polling interval.

### Durability: accepted, with an explicit loss window

Ed accepted this on 6 November: **an acknowledged send lives only in the instance's SQLite buffer
until it is drained to Postgres.** A deploy or reboot replaces the filesystem, and an unexpected
instance or process loss can take the buffer with it. Undrained sends are then lost. This is not
durable-before-ack, and nothing here claims it is. His required safeguards:

1. **Push-to-deploy off for Sesame installs.** Implemented in scalpels.app PR #514 (manifest v7
   `targets.cloud.lifecycle.push_to_deploy: false`), not yet live. Until it is, turn it off by hand.
2. **Plain documentation** that deploying during a test can lose that test's data. It is at the
   top of the README.

What keeps the window small, and how a loss is found:

- Every request registers a deferred drain (`Housekeeping`), so the drain runs on the same
  instance right after the response is flushed, and takes everything any request left behind.
- The drain is idempotent. It copies with `ON CONFLICT DO NOTHING` on stable IDs and deletes from
  SQLite only after the Postgres commit. A file lock serialises drains per buffer.
- Each MessageId carries its buffer's UUID and sequence number. `sesame_buffers` records how far
  each buffer was drained and when it was last seen, so `IngestBuffer::lossFor()`, the
  `/missing` endpoint and `crm:reconcile` can tell `lost` (a simulator loss) from `unknown` (a CRM
  finding).

Measured in the orb ([`evidence/ingest-buffer-loss.json`](evidence/ingest-buffer-loss.json)).
Octane has 4 workers. vegeta runs on the same 2 vCPUs.

| Run | Ingest p50 / p95 / p99 / max | Buffer depth, sampled every 10 ms | Acknowledged but not in Postgres |
| --- | --- | --- | --- |
| Octane, 30/s, 60 s | 3.50 / 4.58 / 5.72 / 10.36 ms | max 1, 90% of samples empty | 0 of 1,800 |
| Octane, 300/s, 120 s | 3.15 / 9.28 / 149 / 293 ms | max 30, p99 5 | 0 of 36,000 |
| Per-request PHP (FrankenPHP classic, like FPM), 30/s, 50 s | 10.55 / 13.88 / 22.79 / 56.31 ms | | 0 of 1,500 |
| **SIGKILL every server process and delete the buffer mid-run**, 30/s, 3 runs | | | **0, 0, 0** (of 212, 215, 225) |
| **Same, at 300/s**, 9 runs | | | **1, 0, 0, 2, 3, 1, 1, 0, 0** (of about 2,100–2,260 each) |

Every lost send whose ID was captured was classified `lost`, with `seq = drained_through + 1` of
the replaced buffer: the sends in flight in the last few milliseconds. These numbers come from a
fast local Postgres. They do not cover the case where the after-response drain cannot run, for
example Postgres being unreachable or the process dying inside the deferred callback. Then the
buffer holds everything until a later request's drain succeeds: unbounded while Postgres is down,
and with no scheduler, until the next request reaches that instance. On Cloud, the Postgres round trip and the deploy's graceful
shutdown are both unmeasured.

**Live run with a deploy in the middle (orb, real processes).** Sesame ran on Octane with
`schedule:work` (drain and sweep every second), 2 queue workers for `DeliverEvents`, and the
harness on its own Octane server with 6 workers. The harness sent 7,368 sends at 30/s under label
`deploy-mid-run`: 5,400 planned, plus about 1,968 when the driver service restarted by mistake. At
13:26:39 the Sesame web service was stopped with SIGTERM, its tmp buffer deleted, and the service
restarted 2.7 s later. Results:

- The CRM recorded 7,293 sends as accepted. **All 7,293 are in Postgres: 0 lost.** A graceful stop
  lets Octane finish the deferred drains. The SIGKILL runs above are the case that loses sends.
- 74 sends failed on the client with connection refused during the restart. They were never
  acknowledged, so they are not losses.
- **32 of 14,642 events were dropped with HTTP 403**, all due between 13:26:39.6 and 13:26:42.2.
  Delivery workers kept posting while the web server was down. Harness workers recycled by Octane's
  request limit had lost their certificate cache, could not fetch `cert.pem` from Sesame, failed
  validation and answered 403, which SNS rules never retry. A deploy therefore loses events as well
  as buffered sends, and `crm:reconcile` reports these as CRM mismatches. On Cloud, an overlapping
  deploy keeps the old instance serving, which may hide this. That is untested.
- At the snapshot, 14,603 events were delivered, 32 dropped and 7 still pending (DeliveryDelay
  finals due by 13:43). There were no failed jobs after the `DeliveryPool` fix. The final
  `crm:reconcile` was still waiting when the orb stopped advancing, so it has no recorded exit
  code. Expect exit 1 with 32 mismatches, all from the deploy.

The same live stack found one real bug that the in-process tests hid. Nothing bound `DeliveryPool`
in the container, so every queued delivery job failed until the binding was added. A regression
test now builds the pool through the container. The three jobs that failed before the fix had their
events redelivered when the leases ran out, which exercised lease recovery on real processes.

**Is this acceptable for a simulator?** Yes, with the reporting above. A lost send can never pass
as a CRM bug, because it is reported as a simulator loss and the run is marked incomplete
(`crm:reconcile` exits 2). It costs a rerun, not a wrong verdict. That holds for buffer losses only. Events dropped
because the certificate URL was down during a deploy are still counted as CRM mismatches, which is
one more reason never to deploy during a run. It would not be acceptable if
Sesame ever had to stand in for SES's own durability.

### Lab findings applied

From scalpels.app `resources/lab`, which already records the Cloud constraints:

- **No persistent volume; a deploy wipes the filesystem.** So the buffer is tmp-only by intent and
  is drained continuously.
- **Hibernation is a freeze-and-thaw of the same container** (same pid, files kept), so a
  hibernating Sesame keeps its buffer, though it is normally empty long before hibernation.
  Scale-to-zero holds the environment at one replica.
- **Old and new instances overlap during a deploy, and deploy commands may run in another
  container.** Each buffer drains only itself, and the sweeper claims with SKIP LOCKED leases, so
  overlapping instances neither collide nor double-deliver.
- **Queue jobs run off-instance and cannot see the instance's local SQLite.** So the drain runs in
  `defer()` on the web instance, never in a queue job. Delivery reads only Postgres.
- **Octane 2.22+ crashes on Cloud's FrankenPHP 1.12.4.** Sesame works without Octane (the
  per-request row above). If Octane is turned on, pin `laravel/octane ~2.21.0` first. Sesame's
  `composer.json` currently requires `^2.23`, which would hit the crash.

### Hosting assumptions not yet tested on Cloud

None of these has run on Laravel Cloud. A live provisioning test needs separate authorization.

- `defer()` callbacks run after the response under Cloud's PHP-FPM and Octane. They run under
  FrankenPHP in the orb.
- Delayed jobs on Cloud's managed queue run at their time while the web app hibernates, delays up
  to 15 minutes are accepted, and the queue's compute wakes for them by itself (Cloud's docs say
  so; not exercised).
- A webhook's `cert.pem` fetch wakes a hibernating app fast enough for the CRM's validator. New
  Flex sizes are documented to wake in under 500 ms, legacy Flex in 5 to 20 s.
- With no cache resource, `CACHE_STORE` stays `database`, so wakeup deduplication uses Postgres.
- `QUEUE_CONNECTION=cloud` delivers `DeliverEvents` within the lease (60 s). The job finishes well
  under Flex's 90 s limit: 25 POSTs with a 15 s timeout.
- With more than one replica, each replica drains its own buffer only when requests reach it. A
  replica removed by scale-down right after its last request relies on that request's drain.

## What was built

This section describes the first PoC. The fast acknowledgement, data plane and WAL bullets are
superseded by the redesign above; everything else still holds.

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
- **Storage**: `sesame:prune` deletes raw data after 7 days and runs at most daily, after a request;
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
| Send latency p50 / p95 (dispatched to accepted, CRM side) | 40.0 / 114.1 ms (p99 526) in a 50 s, 1,500-send rerun; see note | +25% vs baseline |
| Max queue lag | about 1 s (plateau, 1 s resolution); 0.655 s in the rerun | above 30 s |
| Webhook response p50 / p95 / p99 / max | 24.6 / 48.5 / 92.2 / 676.6 ms over 54,252 attempts | +25%, or above 1 s |
| Webhook 5xx and timeouts | 0 | above 0.1% |
| Webhook 4xx (events lost) | 0 | any |
| Event lag p50 / p95 (received to CRM state) | 69 / 249 ms (p99 870) in the rerun; see note | +25% vs baseline |
| Outcomes drawn | delivered 26,220; hard bounce 311; soft bounce 275; DeliveryDelay 132; complaint 62 (411 contacts suppressed) | |
| Events delivered / dropped / pending after drain | 54,252 (54,194 distinct + 58 duplicates) / 0 / 0 | |
| **Reconciliation mismatches** | **0** of 27,000 messages compared | any |

**Note on CRM-side timings.** During the plateau the harness wrote timestamps through Laravel's
query builder, which stores whole seconds, so send latency, queue lag and event lag came out as 0
or 1000 ms and are not usable. The harness now writes millisecond timestamps (`App\CrmHarness\Clock`).
The send-latency and event-lag figures above come from a 50 s, 1,500-send rerun at 30/s
(run `latency-30x50`, seed 20261107) on the same stack, not from the 15-minute plateau. Its p99s
include the orb's stalls. Simulator-side numbers (achieved rate, webhook latency, reconciliation)
come from the plateau itself and were measured at millisecond resolution.

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

After the redesign, `php artisan test` runs 88 tests, all passing, on Postgres. PHPStan level 6
reports 0 errors and Pint is clean.

| Test file | Proves |
| --- | --- |
| `tests/Unit/Sesame/OutcomeLotteryTest.php` | Same seed replays, different seed differs; rates near configured over 20,000 draws; forced local parts (and `bouncer@` is not forced); timing windows respected |
| `tests/Feature/Sesame/SnsSigningTest.php` | Notification (with and without Subject), SubscriptionConfirmation and UnsubscribeConfirmation all verify with the real `MessageValidator`; a tampered field, another account's key, or a validator still bound to `amazonaws.com` all fail; the cert is served at `.pem` |
| `tests/Feature/Sesame/SendEmailTest.php` | The real AWS SDK `SesV2Client` against the endpoint. The MessageId has SES's shape. Tags and headers are captured, folded headers are unfolded, and the body is ignored. The send sits in the buffer until the after-response drain moves it. With the account cached, ingest makes no Postgres queries. Replaying a drain is idempotent. Errors map to SDK error codes. Throttling and Reject are recorded as buffer refusals. |
| `tests/Feature/Sesame/LoopTest.php` | The whole loop in process: harness job, Mailable, `ses-v2` transport, buffer, drain, sweep, queued `DeliverEvents`, signed POST, webhook, queued processing, reconcile. Confirmation gates delivery. 1,000 messages with 30% duplicates and 30% out-of-order delivery reconcile with 0 mismatches. A webhook answering 403 loses events and reconcile fails. 5xx is retried 3 times, then dropped. Lost delivery jobs are redelivered once their lease expires, and a late job settles nothing. Sends in a replaced buffer are reported as simulator losses: reconcile exits 2 with 0 CRM mismatches. A MessageId Sesame never issued still fails reconcile. The container builds the `DeliveryPool` that queue workers use. Accepted work is not resent, and suppressed contacts are skipped. |
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
2. **Runtime decision**: rerun the benchmark on the target host (above). The redesign removes the
   long-running data plane, so the question is now only whether Cloud's PHP ingest holds
   single-digit p99.
3. **Scalpels packaging: built, awaiting approval.** scalpels.app PR #514 adds manifest v7's
   per-app `targets.cloud.lifecycle.push_to_deploy` field and a provisioning step that turns
   push-to-deploy off, and gives Sesame a managed queue (`mq.flex.512mb`), a generated `APP_KEY`,
   `migrate --force` after deploy, hibernation after 10 minutes and no scheduler. Merging it
   deploys scalpels.app production, and the catalog upsert changes the live row, so both wait for
   Ed. This repository's scheduler-free delivery must be on `main` first: under that manifest, the
   earlier code would accept sends and never deliver them.
4. **A live Cloud provisioning test**, which needs separate authorization (see the hosting
   assumptions above).
5. **Phase 2 (measurement)**: ramp steps and ceiling, gates and baselines, daily rollups, the PR
   report, the CI wake/run/sleep flow. Per-event webhook timing and the reconciliation API exist.
6. **Phase 3 (realism) gaps**: suppression memory (`OnAccountSuppressionList` and the "sends to
   suppressed addresses" metric), reputation-driven `SendingPausedException`, injected `SendEmail`
   latency, custom retry policies and `throttlePolicy.maxReceivesPerSecond`,
   `UnsubscribeConfirmation`.
7. **Phase 4 / control plane**: `built-for-cloud` users, dashboard and MCP are not installed. The
   accounts are Artisan-only. Multiple simulated accounts per instance work.
8. **Delivery throughput**: signing and POSTs now run in queued jobs of 25 events, so they scale
   with queue workers. This was measured only at 30/s.

## Scalpels catalog status

Sesame is listed in the Scalpels catalog as an **Experimental** Marketplace app
(https://scalpels.app/products/sesame, docs at https://scalpels.app/docs/sesame), added in
artisan-build/scalpels.app #509 and created on production with
`scalpels:upsert-catalog-app sesame` on 2026-11-06. Experimental means public, a work in
progress, and installable like any Experimental marketplace listing. The **live** manifest declares
PHP 8.5, Postgres and the scheduler but no queue, commands or keys, so **an install from
Scalpels does not work end to end yet**; the listing and its docs say so. scalpels.app PR #514
replaces it with manifest v7: Postgres, a managed queue, a generated `APP_KEY`,
`migrate --force` after deploy, hibernation after 10 minutes, no scheduler, and push-to-deploy
off. It is unmerged, and that install path has never run on Laravel Cloud. The items under
"What is not done" still apply.

## Operating notes

- Sesame needs HTTPS for its certificate URL. Locally, `octane:frankenphp --https` with
  `SESAME_CA_BUNDLE` pointing consumers at Caddy's root CA.
- A second Octane server from the same checkout needs its own `OCTANE_STATE_FILE`.
- The ingest buffer is `sys_get_temp_dir()/sesame/buffer.sqlite` (`SESAME_BUFFER_PATH`). Never
  point it at a shared or persistent path: one file per instance is what the loss accounting
  assumes.
