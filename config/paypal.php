<?php

return [
    'mode' => env('PAYPAL_MODE', 'sandbox'),

    'client_id' => env('PAYPAL_CLIENT_ID'),

    'client_secret' => env('PAYPAL_CLIENT_SECRET'),

    'base_url' => env('PAYPAL_BASE_URL'),

    'webhook_id' => env('PAYPAL_WEBHOOK_ID'),

    'timeout' => (int) env('PAYPAL_TIMEOUT', 12),

    'connect_timeout' => (int) env('PAYPAL_CONNECT_TIMEOUT', 4),

    'access_token_cache_key' => env('PAYPAL_ACCESS_TOKEN_CACHE_KEY', 'paypal.access_token'),

    'access_token_ttl' => (int) env('PAYPAL_ACCESS_TOKEN_TTL', 50),
];
