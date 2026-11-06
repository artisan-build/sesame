<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        // CRM-side wiring from the Sesame brief, used here by the local CRM
        // harness. Staging points it at Sesame; production refuses to boot if set.
        'endpoint' => env('SES_ENDPOINT'),
    ],

    'sns' => [
        'cert_host_pattern' => env('SNS_CERT_HOST_PATTERN'), // staging: /^<sim-host>$/
        // Local orb only: the CA that signed the local TLS proxy's certificate.
        'ca_bundle' => env('SNS_CERT_CA_BUNDLE'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
