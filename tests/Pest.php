<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LeonardGanyire\Paypal\Tests\TestCase;

pest()->extend(TestCase::class)
    ->in('Feature', 'Unit');

function configurePayPal(): void
{
    config([
        'paypal.client_id' => 'paypal-client',
        'paypal.client_secret' => 'paypal-secret',
        'paypal.base_url' => 'https://api-m.sandbox.paypal.com',
        'paypal.webhook_id' => 'WH-123',
    ]);

    Cache::forget('paypal.access_token');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function fakePayPal(string $orderId = 'PAYPAL-ORDER-1', array $overrides = []): void
{
    $baseUrl = 'https://api-m.sandbox.paypal.com';

    Http::fake(array_merge([
        "{$baseUrl}/v1/oauth2/token" => Http::response([
            'access_token' => 'test-token',
            'expires_in' => 3600,
        ], 200),
        "{$baseUrl}/v2/checkout/orders" => Http::response([
            'id' => $orderId,
            'status' => 'CREATED',
            'links' => [
                [
                    'rel' => 'approve',
                    'href' => "https://paypal.test/approve/{$orderId}",
                ],
            ],
        ], 201),
        "{$baseUrl}/v2/checkout/orders/{$orderId}" => Http::response([
            'id' => $orderId,
            'status' => 'APPROVED',
        ], 200),
        "{$baseUrl}/v2/checkout/orders/{$orderId}/capture" => Http::response([
            'status' => 'COMPLETED',
            'id' => $orderId,
            'purchase_units' => [[
                'payments' => [
                    'captures' => [[
                        'id' => 'CAPTURE-1',
                    ]],
                ],
            ]],
        ], 201),
        "{$baseUrl}/v2/checkout/orders/{$orderId}/authorize" => Http::response([
            'status' => 'COMPLETED',
            'id' => $orderId,
        ], 201),
        "{$baseUrl}/v2/payments/captures/CAPTURE-1/refund" => Http::response([
            'id' => 'REFUND-1',
            'status' => 'COMPLETED',
        ], 201),
        "{$baseUrl}/v1/notifications/verify-webhook-signature" => Http::response([
            'verification_status' => 'SUCCESS',
        ], 200),
    ], $overrides));
}
