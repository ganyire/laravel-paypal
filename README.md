# PayPal for Laravel

A frontend-agnostic Laravel package for PayPal Checkout Orders v2. It handles OAuth authentication, order creation, capture, refunds, and webhook signature verification. Your host app owns models, controllers, routes, and any JavaScript UI (Smart Buttons, redirect checkout, mobile API, etc.).

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13

## Installation

```bash
composer require leonardganyire/paypal
```

Publish the config file (optional — the package works with defaults merged automatically):

```bash
php artisan vendor:publish --tag=paypal-config
```

## Configuration

Add these variables to your `.env`:

```env
PAYPAL_MODE=sandbox
PAYPAL_CLIENT_ID=your-client-id
PAYPAL_CLIENT_SECRET=your-client-secret
PAYPAL_WEBHOOK_ID=your-webhook-id

# Optional overrides
# PAYPAL_BASE_URL=https://api-m.sandbox.paypal.com
# PAYPAL_TIMEOUT=12
# PAYPAL_CONNECT_TIMEOUT=4
# PAYPAL_ACCESS_TOKEN_CACHE_KEY=paypal.access_token
# PAYPAL_ACCESS_TOKEN_TTL=50
```

| Variable | Description |
|----------|-------------|
| `PAYPAL_MODE` | `sandbox` or `live`. Used to resolve the API base URL when `PAYPAL_BASE_URL` is not set. |
| `PAYPAL_CLIENT_ID` | PayPal REST app client ID. |
| `PAYPAL_CLIENT_SECRET` | PayPal REST app client secret. |
| `PAYPAL_WEBHOOK_ID` | Webhook ID from the PayPal Developer Dashboard. Required for webhook verification. |
| `PAYPAL_BASE_URL` | Explicit API base URL. If empty, resolved from `PAYPAL_MODE` (`https://api-m.sandbox.paypal.com` or `https://api-m.paypal.com`). |

Access tokens are cached automatically (default TTL: 50 minutes) and cleared on 401 responses.

## Usage

The package registers a `PayPalClient` singleton. Resolve it via the facade or dependency injection.

### Facade

```php
use LeonardGanyire\Paypal\Facades\PayPal;

$order = PayPal::createOrder($payload, idempotencyKey: $paymentId);
$approvalUrl = PayPal::approvalUrl($order);
$capture = PayPal::captureOrder($order['id']);
```

### Dependency injection

```php
use LeonardGanyire\Paypal\PayPalClient;

final class CheckoutController
{
    public function __construct(
        private readonly PayPalClient $paypal,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $order = $this->paypal->createOrder([/* ... */]);

        return redirect()->away($this->paypal->approvalUrl($order));
    }
}
```

## Checkout flow

This package covers the PayPal API layer only. A typical one-time payment in your host app looks like this:

```
1. Create a local payment/order record
2. Call PayPal::createOrder() with purchase details
3. Send the buyer to PayPal (redirect) or return the order ID to your frontend (Smart Buttons)
4. After approval, call PayPal::captureOrder()
5. Mark your local payment as paid and fulfill the order
6. Optionally handle PayPal webhooks as a backup completion path
```

### Step 1 — Create a PayPal order

```php
use LeonardGanyire\Paypal\Facades\PayPal;

$paypalOrder = PayPal::createOrder([
    'intent' => 'CAPTURE',
    'purchase_units' => [[
        'reference_id' => (string) $order->id,
        'description' => "Order {$order->id}",
        'amount' => [
            'currency_code' => strtoupper($order->currency),
            'value' => number_format($order->amount, 2, '.', ''),
        ],
    ]],
    'application_context' => [
        'return_url' => route('payments.paypal.return', $payment),
        'cancel_url' => route('payments.paypal.cancel', $payment),
        'brand_name' => config('app.name'),
        'user_action' => 'PAY_NOW',
    ],
], idempotencyKey: (string) $payment->id);

$paypalOrderId = $paypalOrder['id'];
$approvalUrl = PayPal::approvalUrl($paypalOrder);

// Store $paypalOrderId on your local payment record as provider_reference
```

Pass an idempotency key (e.g. your local payment ID) to avoid duplicate orders on retries.

### Step 2a — Redirect checkout

Redirect the buyer to the approval URL:

```php
return redirect()->away($approvalUrl);
```

After the buyer approves, PayPal redirects to your `return_url` with a `token` query parameter (the PayPal order ID). Validate it matches your stored `provider_reference`, then capture.

### Step 2b — JavaScript Smart Buttons (optional)

If you use `@paypal/react-paypal-js` or the PayPal JS SDK in your frontend:

1. Your frontend calls your checkout endpoint and receives `{ paypalOrderId, paymentId }`.
2. The JS SDK handles approval in the popup.
3. Your frontend POSTs to a capture endpoint in your app.
4. Your capture endpoint calls `PayPal::captureOrder()`.

The package does not ship any frontend code — wire this up however your stack requires.

### Step 3 — Capture the payment

```php
use LeonardGanyire\Paypal\Enums\PayPalOrderStatus;
use LeonardGanyire\Paypal\Exceptions\PayPalException;
use LeonardGanyire\Paypal\Facades\PayPal;

try {
    $capture = PayPal::captureOrder($paypalOrderId);

    $status = PayPalOrderStatus::fromResponse($capture);
    $captureId = PayPalOrderStatus::captureReference($capture);

    if ($status->isCompleted()) {
        // Mark local payment as paid, store $captureId, fulfill order
    }
} catch (PayPalException $exception) {
    // Mark local payment as failed
    // $exception->details() contains PayPal error info
}
```

### Step 4 — Return URL controller (example)

```php
public function __invoke(Request $request, Payment $payment): RedirectResponse
{
    $token = $request->query('token');

    if ($token !== $payment->provider_reference) {
        abort(403);
    }

    $capture = PayPal::captureOrder($token);
    $status = PayPalOrderStatus::fromResponse($capture);

    if ($status->isCompleted()) {
        // Complete local payment and redirect to success page
    }

    return redirect()->route('orders.show', $payment->order);
}
```

## Other API methods

### Get an order

```php
$order = PayPal::getOrder($paypalOrderId);
```

### Authorize (capture later)

```php
$authorization = PayPal::authorizeOrder($paypalOrderId);
```

### Refund a capture

```php
// Full refund
$refund = PayPal::refundCapture($captureId);

// Partial refund
$refund = PayPal::refundCapture($captureId, [
    'amount' => [
        'value' => '5.00',
        'currency_code' => 'USD',
    ],
]);
```

## Webhooks

Register a webhook URL in the PayPal Developer Dashboard pointing to a route in your app, then verify incoming events:

```php
use Illuminate\Http\Request;
use LeonardGanyire\Paypal\Facades\PayPal;

public function __invoke(Request $request): Response
{
    $headers = [
        'paypal-auth-algo'         => $request->header('PAYPAL-AUTH-ALGO'),
        'paypal-cert-url'          => $request->header('PAYPAL-CERT-URL'),
        'paypal-transmission-id'   => $request->header('PAYPAL-TRANSMISSION-ID'),
        'paypal-transmission-sig'  => $request->header('PAYPAL-TRANSMISSION-SIG'),
        'paypal-transmission-time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
    ];

    $payload = $request->all();

    if (! PayPal::verifyWebhookSignature($headers, $payload)) {
        abort(400);
    }

    $eventType = $payload['event_type'] ?? null;

    match ($eventType) {
        'CHECKOUT.ORDER.APPROVED',
        'PAYMENT.CAPTURE.COMPLETED' => $this->handlePaymentCompleted($payload),
        default => null,
    };

    return response('OK');
}
```

Exclude your webhook route from CSRF verification in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: [
        'payments/paypal/webhook',
    ]);
})
```

Webhooks are a backup path. Your primary completion flow should still be the return URL or capture endpoint.

## Status mapping

Use `PayPalOrderStatus` to interpret PayPal responses without coupling to your own payment enums:

```php
use LeonardGanyire\Paypal\Enums\PayPalOrderStatus;

$status = PayPalOrderStatus::fromResponse($capture);

$status->isCompleted();  // true when status is COMPLETED
$status->isCancelled();  // true when status is VOIDED

$captureId = PayPalOrderStatus::captureReference($capture);
```

Supported statuses: `CREATED`, `SAVED`, `APPROVED`, `PAYER_ACTION_REQUIRED`, `VOIDED`, `COMPLETED`.

## Error handling

All API failures throw `LeonardGanyire\Paypal\Exceptions\PayPalException`:

```php
use LeonardGanyire\Paypal\Exceptions\PayPalException;

try {
    PayPal::createOrder($payload);
} catch (PayPalException $exception) {
    $exception->getMessage();  // Human-readable message
    $exception->status();      // HTTP status (default 502)
    $exception->errorCode();   // paypal_request_failed
    $exception->details();     // ['status' => 400, 'name' => '...', 'debug_id' => '...']
}
```

Configuration errors (missing credentials, invalid base URL) also throw `PayPalException` before any HTTP request is made.

## What this package does not include

The following are intentionally left to your host app:

- Payment, order, or transaction models and migrations
- Checkout, return, capture, cancel, or webhook controllers and routes
- Order fulfillment logic (enrollment, subscription activation, etc.)
- Frontend JavaScript or React components
- PayPal Billing Subscriptions / recurring billing API

This keeps the package usable with any frontend (Inertia, Livewire, Blade, SPA, mobile API) and any payment architecture.

## Testing

Run the package test suite:

```bash
composer test
```

In your host app, fake PayPal HTTP calls with Laravel's `Http::fake()`:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
        'access_token' => 'test-token',
        'expires_in' => 3600,
    ]),
    'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
        'id' => 'PAYPAL-ORDER-1',
        'links' => [['rel' => 'approve', 'href' => 'https://paypal.test/approve']],
    ], 201),
    'api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL-ORDER-1/capture' => Http::response([
        'status' => 'COMPLETED',
        'purchase_units' => [[
            'payments' => ['captures' => [['id' => 'CAPTURE-1']]],
        ]],
    ], 201),
]);
```

## License

MIT
