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

    /*
     * Fallback access token cache lifetime, in minutes. Only used when PayPal's
     * OAuth2 response does not include a usable `expires_in` value. When it does
     * (the normal case), the token is cached for that lifetime minus the leeway
     * below so that authentication round-trips are kept to a minimum.
     */
    'access_token_ttl' => (int) env('PAYPAL_ACCESS_TOKEN_TTL', 50),

    /*
     * Safety leeway, in seconds, subtracted from PayPal's reported token
     * lifetime. It guarantees the cached token is refreshed shortly before
     * PayPal considers it expired, avoiding wasted 401 round-trips.
     */
    'access_token_leeway' => (int) env('PAYPAL_ACCESS_TOKEN_LEEWAY', 60),
];
