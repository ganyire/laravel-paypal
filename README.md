# PayPal for Laravel

A frontend-agnostic Laravel package for PayPal Checkout Orders v2. It handles OAuth authentication, access-token caching, order creation, order capture, authorization, refunds, order lookup, approval URL extraction, and webhook signature verification.

Your application still owns the payment model, routes, controllers, validation, fulfillment, and frontend. That keeps this package usable from Blade, Livewire, Inertia, a standalone React app, a mobile app, or any other client that can talk to your Laravel backend.

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13
- A PayPal REST app from the PayPal Developer Dashboard

## Installation

Install the package with Composer:

```bash
composer require leonardganyire/paypal
```

Laravel auto-discovers the service provider and facade. You may publish the config if you want to customize the defaults:

```bash
php artisan vendor:publish --tag=paypal-config
```

## Configuration

Add your PayPal REST app credentials to `.env`:

```env
PAYPAL_MODE=sandbox
PAYPAL_CLIENT_ID=your-paypal-rest-client-id
PAYPAL_CLIENT_SECRET=your-paypal-rest-client-secret
PAYPAL_WEBHOOK_ID=your-paypal-webhook-id

# Optional overrides
# PAYPAL_BASE_URL=https://api-m.sandbox.paypal.com
# PAYPAL_TIMEOUT=12
# PAYPAL_CONNECT_TIMEOUT=4
# PAYPAL_ACCESS_TOKEN_CACHE_KEY=paypal.access_token
# PAYPAL_ACCESS_TOKEN_TTL=50
# PAYPAL_ACCESS_TOKEN_LEEWAY=60
```

Configuration notes:

- `PAYPAL_MODE` should be `sandbox` while testing and `live` in production.
- `PAYPAL_CLIENT_ID` is safe to expose to a browser only when you need to load PayPal's JavaScript SDK. The backend still needs the same value for OAuth.
- `PAYPAL_CLIENT_SECRET` must stay server-side. Never send it to React, Inertia props, mobile apps, logs, or public config.
- `PAYPAL_WEBHOOK_ID` is required only when you call `verifyWebhookSignature()`.
- `PAYPAL_BASE_URL` is usually not needed. If it is empty, the package resolves `https://api-m.sandbox.paypal.com` for sandbox and `https://api-m.paypal.com` for live.

Access tokens are cached automatically. The cache lifetime follows PayPal's `expires_in` value minus `PAYPAL_ACCESS_TOKEN_LEEWAY`, so tokens are refreshed shortly before they expire. `PAYPAL_ACCESS_TOKEN_TTL` is only used as a fallback when PayPal does not return a usable expiry value. When PayPal returns `401`, the package clears the cached token so the next request can authenticate again.

## What The Package Provides

Resolve `LeonardGanyire\Paypal\PayPalClient` from the container or use the `PayPal` facade:

```php
use LeonardGanyire\Paypal\Facades\PayPal;

$paypalOrder = PayPal::createOrder($payload, idempotencyKey: (string) $payment->id);
$approvalUrl = PayPal::approvalUrl($paypalOrder);
$capture = PayPal::captureOrder($paypalOrder['id']);
```

Dependency injection is preferred for application code:

```php
use LeonardGanyire\Paypal\PayPalClient;

final class PayPalCheckoutController
{
    public function __construct(
        private readonly PayPalClient $paypal,
    ) {}
}
```

Available methods:

- `createOrder(array $payload, ?string $idempotencyKey = null): array`
- `captureOrder(string $orderId): array`
- `authorizeOrder(string $orderId): array`
- `getOrder(string $orderId): array`
- `refundCapture(string $captureId, ?array $payload = null): array`
- `verifyWebhookSignature(array $headers, array $payload): bool`
- `approvalUrl(array $orderResponse): ?string`

## Checkout Architecture

For one-time payments, your application should usually follow this flow:

1. The customer starts checkout in your app.
2. Your Laravel backend creates a local pending payment record.
3. Your Laravel backend calls `createOrder()` with server-trusted amount and currency values.
4. Your app either redirects the customer to PayPal or returns the PayPal order ID to React Smart Buttons.
5. After buyer approval, your Laravel backend calls `captureOrder()`.
6. Your app marks the local payment as paid only after PayPal confirms `COMPLETED`.
7. Webhooks act as a recovery path for missed redirects, closed browser tabs, and asynchronous payment events.

Do not trust the amount, currency, user ID, order owner, or product price sent by the browser. Use browser input only to identify the cart/order the authenticated user is trying to pay for, then calculate payable totals on the server.

## Example App Structure

The package does not create database tables. A host app normally has a payment or transaction model with columns like these:

```php
Schema::create('payments', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('user_id')->constrained();
    $table->foreignId('order_id')->constrained();
    $table->string('provider')->default('paypal');
    $table->string('provider_reference')->nullable()->index();
    $table->string('capture_reference')->nullable()->index();
    $table->string('status')->default('pending')->index();
    $table->string('currency', 3);
    $table->decimal('amount', 10, 2);
    $table->json('provider_payload')->nullable();
    $table->timestamps();
});
```

Your model might look like this:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Payment extends Model
{
    protected $fillable = [
        'user_id',
        'order_id',
        'provider',
        'provider_reference',
        'capture_reference',
        'status',
        'currency',
        'amount',
        'provider_payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'provider_payload' => 'array',
        ];
    }
}
```

Replace this with your own domain model. For example, a course app might call this an `EnrollmentPayment`, while an ecommerce app might link it to an `Order`.

## Laravel Routes

For a web or Inertia checkout flow:

```php
use App\Http\Controllers\Payments\PayPalCancelController;
use App\Http\Controllers\Payments\PayPalCaptureController;
use App\Http\Controllers\Payments\PayPalOrderController;
use App\Http\Controllers\Payments\PayPalReturnController;
use App\Http\Controllers\Payments\PayPalWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('payments/paypal')->name('payments.paypal.')->group(function (): void {
    Route::post('/orders', [PayPalOrderController::class, 'store'])->name('orders.store');
    Route::post('/orders/{payment}/capture', [PayPalCaptureController::class, 'store'])->name('orders.capture');
    Route::get('/return/{payment}', PayPalReturnController::class)->name('return');
    Route::get('/cancel/{payment}', PayPalCancelController::class)->name('cancel');
});

Route::post('/payments/paypal/webhook', PayPalWebhookController::class)
    ->name('payments.paypal.webhook');
```

For a separate frontend, put equivalent endpoints in `routes/api.php` and protect them with your chosen authentication strategy, commonly Sanctum or bearer tokens:

```php
Route::middleware('auth:sanctum')->prefix('payments/paypal')->name('api.payments.paypal.')->group(function (): void {
    Route::post('/orders', [PayPalOrderController::class, 'store'])->name('orders.store');
    Route::post('/orders/{payment}/capture', [PayPalCaptureController::class, 'store'])->name('orders.capture');
});
```

## Create A PayPal Order

This example creates a local pending payment, creates a PayPal order, stores PayPal's order ID, and returns either JSON or a redirect depending on how the endpoint was called.

```php
namespace App\Http\Controllers\Payments;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LeonardGanyire\Paypal\PayPalClient;

final class PayPalOrderController
{
    public function __construct(
        private readonly PayPalClient $paypal,
    ) {}

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'mode' => ['sometimes', 'string', 'in:redirect,buttons'],
        ]);

        $order = Order::query()
            ->whereBelongsTo($request->user())
            ->whereKey($validated['order_id'])
            ->firstOrFail();

        $payment = Payment::query()->create([
            'user_id' => $request->user()->id,
            'order_id' => $order->id,
            'provider' => 'paypal',
            'status' => 'pending',
            'currency' => $order->currency,
            'amount' => $order->total,
        ]);

        $paypalOrder = $this->paypal->createOrder([
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) $payment->id,
                'description' => "Order {$order->id}",
                'amount' => [
                    'currency_code' => strtoupper($payment->currency),
                    'value' => number_format((float) $payment->amount, 2, '.', ''),
                ],
            ]],
            'application_context' => [
                'brand_name' => config('app.name'),
                'return_url' => route('payments.paypal.return', $payment),
                'cancel_url' => route('payments.paypal.cancel', $payment),
                'user_action' => 'PAY_NOW',
            ],
        ], idempotencyKey: (string) $payment->id);

        $payment->update([
            'provider_reference' => $paypalOrder['id'] ?? null,
            'provider_payload' => $paypalOrder,
        ]);

        $approvalUrl = $this->paypal->approvalUrl($paypalOrder);

        abort_if($approvalUrl === null, 502, 'PayPal did not return an approval URL.');

        if (($validated['mode'] ?? null) === 'redirect') {
            return redirect()->away($approvalUrl);
        }

        return response()->json([
            'payment_id' => $payment->id,
            'paypal_order_id' => $paypalOrder['id'],
            'approval_url' => $approvalUrl,
        ]);
    }
}
```

Important details:

- Pass a local payment ID as the idempotency key so retries do not create duplicate PayPal orders.
- Store PayPal's order ID in `provider_reference`.
- Return `paypal_order_id` to React Smart Buttons.
- Return or redirect to `approval_url` for redirect checkout.
- Keep the final amount calculation on the backend.

## Redirect Checkout

Redirect checkout is the simplest integration. Your UI submits to `payments.paypal.orders.store` with `mode=redirect`; the controller sends the customer to PayPal's approval URL.

When the buyer approves, PayPal redirects to your `return_url` with a `token` query parameter. The token is the PayPal order ID. Always compare it with the value stored on your local payment before capture:

```php
namespace App\Http\Controllers\Payments;

use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LeonardGanyire\Paypal\Enums\PayPalOrderStatus;
use LeonardGanyire\Paypal\PayPalClient;

final class PayPalReturnController
{
    public function __construct(
        private readonly PayPalClient $paypal,
    ) {}

    public function __invoke(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($request->user()->is($payment->user), 403);

        $paypalOrderId = (string) $request->query('token');

        abort_unless(
            is_string($payment->provider_reference) && $paypalOrderId === $payment->provider_reference,
            403,
        );

        $capture = $this->paypal->captureOrder($paypalOrderId);
        $status = PayPalOrderStatus::fromResponse($capture);

        if ($status->isCompleted()) {
            $payment->update([
                'status' => 'paid',
                'capture_reference' => PayPalOrderStatus::captureReference($capture),
                'provider_payload' => $capture,
            ]);

            // Fulfill the order here: grant access, dispatch shipment, send receipt, etc.
        }

        return redirect()->route('orders.show', $payment->order);
    }
}
```

Handle cancellation separately:

```php
namespace App\Http\Controllers\Payments;

use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PayPalCancelController
{
    public function __invoke(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($request->user()->is($payment->user), 403);

        $payment->update(['status' => 'cancelled']);

        return redirect()
            ->route('orders.show', $payment->order)
            ->with('status', 'PayPal checkout was cancelled.');
    }
}
```

## Capture Endpoint For React

When you use PayPal Smart Buttons, the browser opens the PayPal approval popup. After approval, your frontend must ask Laravel to capture the order.

```php
namespace App\Http\Controllers\Payments;

use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LeonardGanyire\Paypal\Enums\PayPalOrderStatus;
use LeonardGanyire\Paypal\PayPalClient;

final class PayPalCaptureController
{
    public function __construct(
        private readonly PayPalClient $paypal,
    ) {}

    public function store(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($request->user()->is($payment->user), 403);

        $validated = $request->validate([
            'paypal_order_id' => ['required', 'string'],
        ]);

        abort_unless(
            is_string($payment->provider_reference) && $validated['paypal_order_id'] === $payment->provider_reference,
            403,
        );

        $paypalOrderId = $payment->provider_reference;

        if ($payment->status === 'paid') {
            return response()->json([
                'status' => 'paid',
                'payment_id' => $payment->id,
            ]);
        }

        $capture = $this->paypal->captureOrder($paypalOrderId);
        $status = PayPalOrderStatus::fromResponse($capture);

        if ($status->isCompleted()) {
            $payment->update([
                'status' => 'paid',
                'capture_reference' => PayPalOrderStatus::captureReference($capture),
                'provider_payload' => $capture,
            ]);

            // Fulfill the order here.
        }

        $freshPayment = $payment->fresh();

        return response()->json([
            'status' => $freshPayment->status,
            'payment_id' => $freshPayment->id,
            'capture_id' => $freshPayment->capture_reference,
        ]);
    }
}
```

## Inertia + React Smart Buttons

Install PayPal's React package in your Laravel frontend:

```bash
npm install @paypal/react-paypal-js
```

Expose only the public PayPal client ID to the page. For example:

```php
use Inertia\Inertia;

return Inertia::render('checkout/show', [
    'order' => [
        'id' => $order->id,
        'total' => $order->total,
        'currency' => $order->currency,
    ],
    'paypal' => [
        'clientId' => config('paypal.client_id'),
        'currency' => strtoupper($order->currency),
    ],
]);
```

Then render PayPal buttons from your Inertia page. In an application that uses Laravel Wayfinder, prefer generated route functions from `@/actions` or `@/routes` instead of hard-coded strings.

```tsx
import { PayPalButtons, PayPalScriptProvider } from '@paypal/react-paypal-js'
import { useRef } from 'react'

type CheckoutProps = {
    order: {
        id: number
        total: string
        currency: string
    }
    paypal: {
        clientId: string
        currency: string
    }
}

function csrfToken(): string {
    return document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? ''
}

export default function CheckoutShow({ order, paypal }: CheckoutProps) {
    const paymentId = useRef<number | null>(null)

    return (
        <PayPalScriptProvider
            options={{
                clientId: paypal.clientId,
                currency: paypal.currency,
                intent: 'capture',
                components: 'buttons',
            }}
        >
            <PayPalButtons
                style={{ layout: 'vertical' }}
                createOrder={async () => {
                    const response = await fetch('/payments/paypal/orders', {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken(),
                        },
                        body: JSON.stringify({
                            order_id: order.id,
                            mode: 'buttons',
                        }),
                    })

                    if (! response.ok) {
                        throw new Error('Unable to create PayPal order.')
                    }

                    const payload = await response.json()

                    paymentId.current = payload.payment_id

                    return payload.paypal_order_id
                }}
                onApprove={async (data) => {
                    if (! paymentId.current || ! data.orderID) {
                        throw new Error('Missing PayPal approval data.')
                    }

                    const response = await fetch(`/payments/paypal/orders/${paymentId.current}/capture`, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken(),
                        },
                        body: JSON.stringify({
                            paypal_order_id: data.orderID,
                        }),
                    })

                    if (! response.ok) {
                        throw new Error('Unable to capture PayPal order.')
                    }

                    window.location.href = `/orders/${order.id}`
                }}
                onCancel={() => {
                    window.location.href = `/orders/${order.id}`
                }}
            />
        </PayPalScriptProvider>
    )
}
```

Notes for Inertia apps:

- Keep the frontend amount display informational; the backend should calculate and send the PayPal amount.
- If you use Wayfinder, replace the string URLs with generated imports from `@/actions/...` or `@/routes/...`.
- If your PayPal buttons do not render, confirm the script provider includes `components: 'buttons'`.
- You can use Inertia navigation after capture, but the PayPal callbacks themselves are plain JavaScript callbacks, so `fetch()` is often the clearest option.

## Standalone React Frontend

A standalone React, Next.js, Vite, or mobile frontend uses the same backend endpoints, but it must handle authentication, CORS, and CSRF according to your Laravel API setup.

Install PayPal's React package:

```bash
npm install @paypal/react-paypal-js
```

Use a public environment variable for the PayPal client ID:

```env
VITE_PAYPAL_CLIENT_ID=your-paypal-rest-client-id
VITE_API_BASE_URL=https://api.example.com
```

Example React component:

```tsx
import { PayPalButtons, PayPalScriptProvider } from '@paypal/react-paypal-js'
import { useRef } from 'react'

type PayPalCheckoutButtonProps = {
    orderId: number
    currency: string
    apiToken?: string
}

const apiBaseUrl = import.meta.env.VITE_API_BASE_URL
const paypalClientId = import.meta.env.VITE_PAYPAL_CLIENT_ID

export function PayPalCheckoutButton({ orderId, currency, apiToken }: PayPalCheckoutButtonProps) {
    const paymentId = useRef<number | null>(null)

    return (
        <PayPalScriptProvider
            options={{
                clientId: paypalClientId,
                currency,
                intent: 'capture',
                components: 'buttons',
            }}
        >
            <PayPalButtons
                createOrder={async () => {
                    const response = await fetch(`${apiBaseUrl}/api/payments/paypal/orders`, {
                        method: 'POST',
                        credentials: 'include',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            ...(apiToken ? { Authorization: `Bearer ${apiToken}` } : {}),
                        },
                        body: JSON.stringify({
                            order_id: orderId,
                            mode: 'buttons',
                        }),
                    })

                    if (! response.ok) {
                        throw new Error('Unable to create PayPal order.')
                    }

                    const payload = await response.json()

                    paymentId.current = payload.payment_id

                    return payload.paypal_order_id
                }}
                onApprove={async (data) => {
                    if (! paymentId.current || ! data.orderID) {
                        throw new Error('Missing PayPal approval data.')
                    }

                    const response = await fetch(`${apiBaseUrl}/api/payments/paypal/orders/${paymentId.current}/capture`, {
                        method: 'POST',
                        credentials: 'include',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            ...(apiToken ? { Authorization: `Bearer ${apiToken}` } : {}),
                        },
                        body: JSON.stringify({
                            paypal_order_id: data.orderID,
                        }),
                    })

                    if (! response.ok) {
                        throw new Error('Unable to capture PayPal order.')
                    }
                }}
            />
        </PayPalScriptProvider>
    )
}
```

Standalone frontend checklist:

- Configure Laravel CORS to allow the frontend origin.
- If using Sanctum cookie auth, call `/sanctum/csrf-cookie` before the first state-changing request and send `credentials: 'include'`.
- If using token auth, send an `Authorization: Bearer ...` header.
- Keep `PAYPAL_CLIENT_SECRET` only in Laravel.
- Do not let the frontend choose the final amount. Send an order/cart ID and let Laravel calculate the PayPal purchase unit.

## Refunds

After a successful capture, store the capture ID from `PayPalOrderStatus::captureReference($capture)`. Use it for refunds.

```php
use LeonardGanyire\Paypal\Facades\PayPal;

// Full refund
$refund = PayPal::refundCapture($payment->capture_reference);

// Partial refund
$refund = PayPal::refundCapture($payment->capture_reference, [
    'amount' => [
        'value' => '5.00',
        'currency_code' => 'USD',
    ],
]);
```

Your application should also store refund state locally and prevent refunding more than the captured amount.

## Authorize Now, Capture Later

If your business flow needs authorization first and capture later, create the order with `intent` set to `AUTHORIZE` and call `authorizeOrder()` after approval:

```php
$paypalOrder = PayPal::createOrder([
    'intent' => 'AUTHORIZE',
    'purchase_units' => [[
        'amount' => [
            'currency_code' => 'USD',
            'value' => '100.00',
        ],
    ]],
]);

$authorization = PayPal::authorizeOrder($paypalOrder['id']);
```

This package does not currently provide a separate "capture authorization" helper. If you need that flow, add the missing endpoint in your app or extend the package.

## Get An Order

Use `getOrder()` when you need to reconcile state with PayPal:

```php
$paypalOrder = PayPal::getOrder($payment->provider_reference);
```

This is useful for admin troubleshooting, webhook reconciliation, or checking an order before attempting capture.

## Webhooks

Webhooks are important, but they should not be your only completion path. Use them to recover from missed redirects, closed tabs, delayed events, and asynchronous updates.

In the PayPal Developer Dashboard, create a webhook pointing to your Laravel route, for example:

```text
https://example.com/payments/paypal/webhook
```

Select the events your app needs. Common one-time checkout events include:

- `CHECKOUT.ORDER.APPROVED`
- `PAYMENT.CAPTURE.COMPLETED`
- `PAYMENT.CAPTURE.DENIED`
- `PAYMENT.CAPTURE.REFUNDED`
- `PAYMENT.CAPTURE.REVERSED`

Webhook controller example:

```php
namespace App\Http\Controllers\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LeonardGanyire\Paypal\Facades\PayPal;

final class PayPalWebhookController
{
    public function __invoke(Request $request): Response
    {
        $headers = [
            'paypal-auth-algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'paypal-cert-url' => $request->header('PAYPAL-CERT-URL'),
            'paypal-transmission-id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'paypal-transmission-sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'paypal-transmission-time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
        ];

        $payload = $request->all();

        if (! PayPal::verifyWebhookSignature($headers, $payload)) {
            abort(400, 'Invalid PayPal webhook signature.');
        }

        $eventType = $payload['event_type'] ?? null;
        $resource = $payload['resource'] ?? [];

        match ($eventType) {
            'PAYMENT.CAPTURE.COMPLETED' => $this->markCaptureCompleted($resource),
            'PAYMENT.CAPTURE.REFUNDED' => $this->markCaptureRefunded($resource),
            default => null,
        };

        return response('OK');
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function markCaptureCompleted(array $resource): void
    {
        $captureId = $resource['id'] ?? null;

        if (! is_string($captureId)) {
            return;
        }

        Payment::query()
            ->where('capture_reference', $captureId)
            ->where('status', '!=', 'paid')
            ->update(['status' => 'paid']);
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function markCaptureRefunded(array $resource): void
    {
        $captureId = $resource['supplementary_data']['related_ids']['capture_id'] ?? null;

        if (! is_string($captureId)) {
            return;
        }

        Payment::query()
            ->where('capture_reference', $captureId)
            ->update(['status' => 'refunded']);
    }
}
```

Exclude the webhook route from CSRF verification in `bootstrap/app.php`:

```php
use Illuminate\Foundation\Configuration\Middleware;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: [
        'payments/paypal/webhook',
    ]);
})
```

Webhook handling tips:

- Verify the webhook signature before trusting any payload fields.
- Make webhook processing idempotent. PayPal may retry events.
- Store enough local state to ignore duplicate events safely.
- Return a `2xx` response after successful processing so PayPal does not keep retrying.

## Status Mapping

Use `PayPalOrderStatus` to interpret PayPal responses without coupling your app to raw PayPal status strings:

```php
use LeonardGanyire\Paypal\Enums\PayPalOrderStatus;

$status = PayPalOrderStatus::fromResponse($capture);

$status->isCompleted();
$status->isCancelled();

$captureId = PayPalOrderStatus::captureReference($capture);
```

Supported statuses are `CREATED`, `SAVED`, `APPROVED`, `PAYER_ACTION_REQUIRED`, `VOIDED`, and `COMPLETED`.

## Error Handling

All PayPal API failures throw `LeonardGanyire\Paypal\Exceptions\PayPalException`:

```php
use LeonardGanyire\Paypal\Exceptions\PayPalException;
use LeonardGanyire\Paypal\Facades\PayPal;

try {
    $paypalOrder = PayPal::createOrder($payload);
} catch (PayPalException $exception) {
    report($exception);

    return response()->json([
        'message' => $exception->getMessage(),
        'code' => $exception->errorCode(),
        'details' => $exception->details(),
    ], $exception->status());
}
```

The exception exposes:

- `$exception->getMessage()` for a human-readable message.
- `$exception->status()` for the HTTP status your app can return, defaulting to `502`.
- `$exception->errorCode()` for a stable application-level error code.
- `$exception->details()` for safe PayPal error metadata such as `status`, `name`, `message`, and `debug_id`.

Configuration errors, such as missing credentials or an invalid base URL, also throw `PayPalException` before a PayPal request is made.

## Security Checklist

- Keep `PAYPAL_CLIENT_SECRET` server-side only.
- Calculate payment amounts on the backend from trusted order/cart records.
- Authorize access to local orders and payments before creating or capturing PayPal orders.
- Verify the returned PayPal order ID matches your stored `provider_reference`.
- Use idempotency keys when creating PayPal orders.
- Treat webhooks as untrusted until `verifyWebhookSignature()` returns `true`.
- Make capture and webhook handlers idempotent so duplicate requests do not double-fulfill orders.
- Store PayPal `debug_id` values from exceptions when you need support or reconciliation.

## What This Package Does Not Include

The following are intentionally left to your host app:

- Payment, order, transaction, refund, or subscription models and migrations.
- Checkout, return, cancel, capture, refund, or webhook controllers and routes.
- Authorization policies for your orders and payments.
- Order fulfillment logic such as enrollment, shipping, invoicing, or receipt emails.
- React, Inertia, Blade, Livewire, Vue, mobile, or other frontend components.
- PayPal Billing Subscriptions and recurring billing APIs.

## Testing

Run the package test suite:

```bash
composer test
```

In your host app, fake PayPal HTTP calls with Laravel's HTTP fake:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
        'access_token' => 'test-token',
        'expires_in' => 3600,
    ]),
    'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
        'id' => 'PAYPAL-ORDER-1',
        'links' => [
            [
                'rel' => 'approve',
                'href' => 'https://paypal.test/approve/PAYPAL-ORDER-1',
            ],
        ],
    ], 201),
    'api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL-ORDER-1/capture' => Http::response([
        'status' => 'COMPLETED',
        'purchase_units' => [[
            'payments' => [
                'captures' => [
                    ['id' => 'CAPTURE-1'],
                ],
            ],
        ]],
    ], 201),
]);
```

Example Pest assertion for your host app:

```php
use App\Models\Order;
use Illuminate\Support\Facades\Http;

it('captures a paypal payment', function (): void {
    Http::preventStrayRequests();

    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'test-token',
            'expires_in' => 3600,
        ]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL-ORDER-1/capture' => Http::response([
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'payments' => [
                    'captures' => [
                        ['id' => 'CAPTURE-1'],
                    ],
                ],
            ]],
        ], 201),
    ]);

    $order = Order::factory()->create();
    $payment = $order->payment()->create([
        'user_id' => $order->user_id,
        'provider' => 'paypal',
        'provider_reference' => 'PAYPAL-ORDER-1',
        'status' => 'pending',
        'currency' => 'USD',
        'amount' => '25.00',
    ]);

    $this->actingAs($order->user)
        ->postJson(route('payments.paypal.orders.capture', $payment), [
            'paypal_order_id' => 'PAYPAL-ORDER-1',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'paid');

    expect($payment->fresh()->capture_reference)->toBe('CAPTURE-1');
});
```

## License

MIT
