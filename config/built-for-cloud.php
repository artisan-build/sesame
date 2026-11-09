<?php

return [
    'product' => 'Sesame',
    'manifest' => [
        'name' => 'Sesame',
        'slug' => 'sesame',
        'description' => 'A simulated Amazon SES for staging load runs, with signed SNS feedback and reconciliation ground truth.',
        'icon' => 'https://scalpels.app/img/products/transparent/sesame.png',
        'product_url' => 'https://scalpels.app/products/sesame',
    ],
    'mcp' => [
        'path' => '/mcp',
        'delegated' => true,
    ],
    'dashboard_prompts' => [
        ['title' => 'Inspect simulator accounts', 'ask' => 'List the simulated SES accounts in this Sesame installation and their current runs.'],
        ['title' => 'Reconcile a load run', 'ask' => 'Show the latest Sesame runs, summarize their outcomes and webhook delivery, and inspect their ground truth.'],
    ],
];
