<?php

/*
|--------------------------------------------------------------------------
| Sesame — SES simulator
|--------------------------------------------------------------------------
|
| Every value has a working default; nothing here must be set by an
| installer. Simulated accounts, presets per account and webhooks live in
| Postgres and are managed through Artisan commands.
|
*/

return [

    // Public HTTPS origin the simulator is reached at. It forms SigningCertURL,
    // SubscribeURL and UnsubscribeURL. The SNS validator requires https.
    'public_url' => env('SESAME_PUBLIC_URL', env('APP_URL', 'https://localhost')),

    // Simulated AWS region and account number used in ARNs.
    'region' => env('SESAME_REGION', 'us-east-1'),

    'buffer' => [
        // Per-instance SQLite ingest buffer. It lives in the temporary
        // directory on purpose: Laravel Cloud replaces it on every deploy and
        // reboot, and Sesame drains it to Postgres within moments of each
        // send, so nothing here is meant to outlive the instance. Sends not
        // yet drained when the instance goes are lost; see IngestBuffer.
        'path' => env('SESAME_BUFFER_PATH', sys_get_temp_dir().'/sesame/buffer.sqlite'),
        // The after-response drain takes every row it can, in batches of
        // this many, until the buffer is empty or this many seconds pass.
        // Kept well under the worker's request limits; what is left waits
        // for the next request's drain.
        'drain_batch' => 500,
        'drain_seconds' => 5.0,
    ],

    // How often, per instance and at most, a request's after-response work
    // also heartbeats the buffer and sweeps overdue events. 0 turns it off.
    'housekeeping_seconds' => (int) env('SESAME_HOUSEKEEPING_SECONDS', 10),

    // How long an instance may reuse an account row before reading it again.
    // Control-plane changes reach every replica within this window.
    'account_cache_ms' => (int) env('SESAME_ACCOUNT_CACHE_MS', 1000),

    'delivery' => [
        // Delayed SweepDue jobs wake when events fall due and lease them to
        // queued DeliverEvents jobs in batches of this size.
        'batch' => 25,
        // Upper bound on events one sweep hands out.
        'sweep_limit' => 5000,
        // A lease that is not settled in this long is handed out again, so a
        // lost or crashed job only delays its events. Must exceed the job's
        // worst case (timeout + drain margin).
        'lease_seconds' => 60,
        // Concurrent POSTs inside one job.
        'concurrency' => 25,
        'timeout_seconds' => 15,
        // SNS default HTTP delivery policy: 3 retries, 20 s apart, linear.
        'retry' => ['retries' => 3, 'delay_seconds' => 20],
        // The queue's longest delay (SQS: 15 minutes). A wakeup due later
        // re-queues itself until its time.
        'max_delay_seconds' => (int) env('SESAME_MAX_DELAY_SECONDS', 900),
    ],

    'retention_days' => 7,

    'defaults' => [
        'preset' => 'healthy',
        // Mirror the client's real SES quota per simulated account.
        'max_send_rate' => 200,
    ],

    /*
    | Outcome rates per recipient, in percent. "delivered" takes the rest.
    | Anchored on SES enforcement lines; "healthy" values are assumptions.
    */
    'presets' => [
        'healthy' => ['hard_bounce' => 0.8, 'soft_bounce' => 1.0, 'complaint' => 0.05, 'delay' => 0.5, 'reject' => 0.01, 'duplicate' => 0.2, 'out_of_order' => 0.5],
        'review' => ['hard_bounce' => 5.5, 'soft_bounce' => 2.0, 'complaint' => 0.12, 'delay' => 1.0, 'reject' => 0.01, 'duplicate' => 0.2, 'out_of_order' => 0.5],
        'paused' => ['hard_bounce' => 11.0, 'soft_bounce' => 3.0, 'complaint' => 0.6, 'delay' => 2.0, 'reject' => 0.01, 'duplicate' => 0.2, 'out_of_order' => 0.5],
        'chaos' => ['hard_bounce' => 3.0, 'soft_bounce' => 5.0, 'complaint' => 0.3, 'delay' => 5.0, 'reject' => 1.0, 'duplicate' => 3.0, 'out_of_order' => 5.0],
    ],

    // Compressed event timing, in seconds: [min, max].
    'timing' => [
        'send' => [0, 0],
        'delivery' => [1, 5],
        'bounce' => [1, 30],
        'complaint' => [30, 600],
        'delay_final' => [60, 900],
    ],

];
