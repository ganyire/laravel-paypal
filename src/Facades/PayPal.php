<?php

namespace LeonardGanyire\Paypal\Facades;

use Illuminate\Support\Facades\Facade;
use LeonardGanyire\Paypal\PayPalClient;

/**
 * @method static array<string, mixed> createOrder(array $payload, ?string $idempotencyKey = null)
 * @method static array<string, mixed> captureOrder(string $orderId)
 * @method static array<string, mixed> authorizeOrder(string $orderId)
 * @method static array<string, mixed> getOrder(string $orderId)
 * @method static array<string, mixed> refundCapture(string $captureId, ?array $payload = null)
 * @method static bool verifyWebhookSignature(array $headers, array $payload)
 * @method static string|null approvalUrl(array $orderResponse)
 *
 * @see PayPalClient
 */
final class PayPal extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'paypal';
    }
}
