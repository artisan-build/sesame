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

    'wal' => [
        // Write-ahead log: every accepted SendEmail is appended here before the
        // MessageId is returned and before any database write.
        'path' => env('SESAME_WAL_PATH', storage_path('sesame/wal')),
        // fsync each append. Turning it off trades crash durability for latency.
        'fsync' => (bool) env('SESAME_WAL_FSYNC', true),
        // Config snapshot the ingest workers read; refreshed by the data plane
        // on Postgres LISTEN/NOTIFY.
        'snapshot' => env('SESAME_SNAPSHOT_PATH', storage_path('sesame/accounts.json')),
    ],

    'dataplane' => [
        'tick_ms' => 20,
        'ingest_batch' => 2000,
        'delivery_concurrency' => 32,
        'delivery_timeout_seconds' => 15,
        // SNS default HTTP delivery policy: 3 retries, 20 s apart, linear.
        'retry' => ['retries' => 3, 'delay_seconds' => 20],
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
