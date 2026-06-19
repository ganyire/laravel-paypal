<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LeonardGanyire\Paypal\Exceptions\PayPalException;
use LeonardGanyire\Paypal\Facades\PayPal;
use LeonardGanyire\Paypal\PayPalClient;

it('creates a paypal order', function () {
    configurePayPal();
    fakePayPal();

    $client = app(PayPalClient::class);

    $response = $client->createOrder([
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'amount' => [
                'currency_code' => 'USD',
                'value' => '10.00',
            ],
        ]],
    ], 'payment-1');

    expect($response['id'])->toBe('PAYPAL-ORDER-1');
    expect($client->approvalUrl($response))->toBe('https://paypal.test/approve/PAYPAL-ORDER-1');
});

it('captures a paypal order', function () {
    configurePayPal();
    fakePayPal();

    $client = app(PayPalClient::class);

    $response = $client->captureOrder('PAYPAL-ORDER-1');

    expect($response['status'])->toBe('COMPLETED');
});

it('authorizes a paypal order', function () {
    configurePayPal();
    fakePayPal();

    $client = app(PayPalClient::class);

    $response = $client->authorizeOrder('PAYPAL-ORDER-1');

    expect($response['status'])->toBe('COMPLETED');
});

it('gets a paypal order', function () {
    configurePayPal();
    fakePayPal();

    $client = app(PayPalClient::class);

    $response = $client->getOrder('PAYPAL-ORDER-1');

    expect($response['status'])->toBe('APPROVED');
});

it('refunds a capture', function () {
    configurePayPal();
    fakePayPal();

    $client = app(PayPalClient::class);

    $response = $client->refundCapture('CAPTURE-1');

    expect($response['id'])->toBe('REFUND-1');
});

it('caches the access token', function () {
    configurePayPal();
    fakePayPal();

    $client = app(PayPalClient::class);

    $client->createOrder(['intent' => 'CAPTURE']);
    $client->createOrder(['intent' => 'CAPTURE']);

    Http::assertSentCount(3);
});

it('caches the access token for the lifetime reported by paypal', function () {
    configurePayPal();

    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'test-token',
            'expires_in' => 120,
        ], 200),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'PAYPAL-ORDER-1',
            'links' => [],
        ], 201),
    ]);

    $client = app(PayPalClient::class);

    $client->createOrder(['intent' => 'CAPTURE']);

    $this->travel(3)->minutes();

    $client->createOrder(['intent' => 'CAPTURE']);

    // expires_in (120s) minus the 60s leeway means the token expires after ~60s,
    // so the second call re-authenticates: 2 token + 2 order requests.
    Http::assertSentCount(4);
});

it('does not re-authenticate within the reported token lifetime', function () {
    configurePayPal();

    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'test-token',
            'expires_in' => 32400,
        ], 200),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'PAYPAL-ORDER-1',
            'links' => [],
        ], 201),
    ]);

    $client = app(PayPalClient::class);

    $client->createOrder(['intent' => 'CAPTURE']);

    $this->travel(50)->minutes();

    $client->createOrder(['intent' => 'CAPTURE']);

    // The long-lived token is still valid, so only 1 token request is made: 1 token + 2 orders.
    Http::assertSentCount(3);
});

it('falls back to the configured ttl when paypal omits expires_in', function () {
    configurePayPal();
    config(['paypal.access_token_ttl' => 1]);

    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'test-token',
        ], 200),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'PAYPAL-ORDER-1',
            'links' => [],
        ], 201),
    ]);

    $client = app(PayPalClient::class);

    $client->createOrder(['intent' => 'CAPTURE']);

    $this->travel(2)->minutes();

    $client->createOrder(['intent' => 'CAPTURE']);

    // Fallback TTL of 1 minute has elapsed, so the token is re-fetched.
    Http::assertSentCount(4);
});

it('forgets cached token on 401 responses', function () {
    configurePayPal();

    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'test-token',
            'expires_in' => 3600,
        ], 200),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::sequence()
            ->push(['message' => 'Unauthorized'], 401)
            ->push([
                'id' => 'PAYPAL-ORDER-1',
                'links' => [],
            ], 201),
    ]);

    $client = app(PayPalClient::class);

    try {
        $client->createOrder(['intent' => 'CAPTURE']);
    } catch (PayPalException) {
        //
    }

    expect(Cache::has('paypal.access_token'))->toBeFalse();
});

it('throws when paypal credentials are missing', function () {
    config([
        'paypal.client_id' => null,
        'paypal.client_secret' => null,
        'paypal.base_url' => 'https://api-m.sandbox.paypal.com',
    ]);

    app(PayPalClient::class)->createOrder(['intent' => 'CAPTURE']);
})->throws(PayPalException::class, 'PayPal is not configured');

it('extracts approval url from payer-action link', function () {
    configurePayPal();

    $client = app(PayPalClient::class);

    $url = $client->approvalUrl([
        'links' => [[
            'rel' => 'payer-action',
            'href' => 'https://paypal.test/payer-action',
        ]],
    ]);

    expect($url)->toBe('https://paypal.test/payer-action');
});

it('verifies webhook signatures', function () {
    configurePayPal();
    fakePayPal();

    $client = app(PayPalClient::class);

    $verified = $client->verifyWebhookSignature(
        headers: [
            'paypal-auth-algo' => 'SHA256withRSA',
            'paypal-cert-url' => 'https://api.sandbox.paypal.com/cert',
            'paypal-transmission-id' => 'abc',
            'paypal-transmission-sig' => 'sig',
            'paypal-transmission-time' => '2026-01-01T00:00:00Z',
        ],
        payload: [
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        ],
    );

    expect($verified)->toBeTrue();
});

it('rejects invalid webhook signatures', function () {
    configurePayPal();
    fakePayPal('PAYPAL-ORDER-1', [
        'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
            'verification_status' => 'FAILURE',
        ], 200),
    ]);

    $client = app(PayPalClient::class);

    $verified = $client->verifyWebhookSignature(
        headers: [
            'paypal-auth-algo' => 'SHA256withRSA',
            'paypal-cert-url' => 'https://api.sandbox.paypal.com/cert',
            'paypal-transmission-id' => 'abc',
            'paypal-transmission-sig' => 'sig',
            'paypal-transmission-time' => '2026-01-01T00:00:00Z',
        ],
        payload: [
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        ],
    );

    expect($verified)->toBeFalse();
});

it('returns false when webhook id is not configured', function () {
    configurePayPal();
    config(['paypal.webhook_id' => null]);
    fakePayPal();

    $client = app(PayPalClient::class);

    expect($client->verifyWebhookSignature([], []))->toBeFalse();
});

it('resolves sandbox base url from mode when base_url is empty', function () {
    configurePayPal();
    config([
        'paypal.base_url' => null,
        'paypal.mode' => 'sandbox',
    ]);
    fakePayPal();

    $response = PayPal::createOrder(['intent' => 'CAPTURE']);

    expect($response['id'])->toBe('PAYPAL-ORDER-1');
});

it('exposes exception details on failed requests', function () {
    configurePayPal();

    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'test-token',
        ], 200),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'name' => 'INVALID_REQUEST',
            'message' => 'Request is not well-formed.',
            'debug_id' => 'abc123',
        ], 400),
    ]);

    try {
        app(PayPalClient::class)->createOrder(['intent' => 'CAPTURE']);
        expect(false)->toBeTrue('Expected PayPalException was not thrown.');
    } catch (PayPalException $exception) {
        expect($exception->status())->toBe(502);
        expect($exception->errorCode())->toBe('paypal_request_failed');
        expect($exception->details())->toMatchArray([
            'status' => 400,
            'name' => 'INVALID_REQUEST',
            'message' => 'Request is not well-formed.',
            'debug_id' => 'abc123',
        ]);
    }
});
