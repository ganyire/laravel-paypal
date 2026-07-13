<?php
namespace LeonardGanyire\Paypal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LeonardGanyire\Paypal\Enums\PayPalOrderStatus;
use LeonardGanyire\Paypal\Exceptions\PayPalException;
use Throwable;

/**
 * Thin client around the PayPal Checkout Orders v2 and Payments v2 REST APIs.
 *
 * The client handles OAuth2 authentication (with cached access tokens),
 * applies sensible timeouts and connection retries, and normalises every
 * failure into a {@see PayPalException}. It is registered as a singleton, so
 * the cached access token is shared across the whole request lifecycle.
 */
final class PayPalClient
{
    /**
     * Create a PayPal Checkout order.
     *
     * @param  array<string, mixed>  $payload  The Orders v2 request body (intent, purchase_units, etc.).
     * @param  string|null  $idempotencyKey  Optional value sent as the `PayPal-Request-Id` header to make
     *                                       retries safe and prevent duplicate orders.
     * @return array<string, mixed> The decoded order resource.
     *
     * @throws PayPalException When PayPal rejects the request or the package is misconfigured.
     */
    public function createOrder(array $payload, ?string $idempotencyKey = null): array
    {
        $request = $this->authenticatedRequest();

        if ($idempotencyKey !== null) {
            $request = $request->withHeaders(['PayPal-Request-Id' => $idempotencyKey]);
        }

        $response = $request->post($this->endpoint('/v2/checkout/orders'), $payload);

        return $this->parseResponse($response);
    }

    /**
     * Capture payment for an approved order.
     *
     * @param  string  $orderId  The PayPal order ID returned by {@see createOrder()}.
     * @return array<string, mixed> The decoded capture result.
     *
     * @throws PayPalException When PayPal rejects the request or the package is misconfigured.
     */
    public function captureOrder(string $orderId): array
    {
        $response = $this->authenticatedRequest()
            ->withBody('{}', 'application/json')
            ->post($this->endpoint("/v2/checkout/orders/{$orderId}/capture"));

        return $this->parseResponse($response);
    }

    /**
     * Authorize payment for an approved order so it can be captured later.
     *
     * @param  string  $orderId  The PayPal order ID returned by {@see createOrder()}.
     * @return array<string, mixed> The decoded authorization result.
     *
     * @throws PayPalException When PayPal rejects the request or the package is misconfigured.
     */
    public function authorizeOrder(string $orderId): array
    {
        $response = $this->authenticatedRequest()
            ->withBody('{}', 'application/json')
            ->post($this->endpoint("/v2/checkout/orders/{$orderId}/authorize"));

        return $this->parseResponse($response);
    }

    /**
     * Retrieve the current state of an order.
     *
     * @param  string  $orderId  The PayPal order ID returned by {@see createOrder()}.
     * @return array<string, mixed> The decoded order resource.
     *
     * @throws PayPalException When PayPal rejects the request or the package is misconfigured.
     */
    public function getOrder(string $orderId): array
    {
        $response = $this->authenticatedRequest()
            ->get($this->endpoint("/v2/checkout/orders/{$orderId}"));

        return $this->parseResponse($response);
    }

    /**
     * Refund a captured payment, in full or in part.
     *
     * @param  string  $captureId  The capture ID, e.g. from {@see PayPalOrderStatus::captureReference()}.
     * @param  array<string, mixed>|null  $payload  Optional refund body (e.g. partial `amount`). A `null` payload issues a full refund.
     * @return array<string, mixed> The decoded refund resource.
     *
     * @throws PayPalException When PayPal rejects the request or the package is misconfigured.
     */
    public function refundCapture(string $captureId, ?array $payload = null): array
    {
        $request = $this->authenticatedRequest();

        if ($payload === null) {
            $response = $request
                ->withBody('{}', 'application/json')
                ->post($this->endpoint("/v2/payments/captures/{$captureId}/refund"));
        } else {
            $response = $request->post($this->endpoint("/v2/payments/captures/{$captureId}/refund"), $payload);
        }

        return $this->parseResponse($response);
    }

    /**
     * Verify the authenticity of an incoming PayPal webhook against the
     * configured `webhook_id` using PayPal's verification endpoint.
     *
     * Returns `false` (rather than throwing) for any failure — missing webhook
     * ID, a failed verification call, or a non-`SUCCESS` status — so callers can
     * safely reject unverified events.
     *
     * @param  array<string, mixed>  $headers  Lower-cased PayPal transmission headers (auth-algo, cert-url, transmission-id/sig/time).
     * @param  array<string, mixed>  $payload  The raw webhook event body.
     */
    public function verifyWebhookSignature(array $headers, array $payload): bool
    {
        $webhookId = config('paypal.webhook_id');

        if (! is_string($webhookId) || $webhookId === '') {
            return false;
        }

        $response = $this->authenticatedRequest()->post(
            $this->endpoint('/v1/notifications/verify-webhook-signature'),
            [
                'auth_algo'         => $headers['paypal-auth-algo'] ?? null,
                'cert_url'          => $headers['paypal-cert-url'] ?? null,
                'transmission_id'   => $headers['paypal-transmission-id'] ?? null,
                'transmission_sig'  => $headers['paypal-transmission-sig'] ?? null,
                'transmission_time' => $headers['paypal-transmission-time'] ?? null,
                'webhook_id'        => $webhookId,
                'webhook_event'     => $payload,
            ],
        );

        if ($response->failed()) {
            return false;
        }

        return ($response->json('verification_status') ?? '') === 'SUCCESS';
    }

    /**
     * Extract the buyer-facing approval/redirect URL from an order response.
     *
     * Looks for the HATEOAS link with a `rel` of `approve` or `payer-action`.
     *
     * @param  array<string, mixed>  $orderResponse  An order resource as returned by {@see createOrder()}.
     * @return string|null The approval URL, or `null` when none is present.
     */
    public function approvalUrl(array $orderResponse): ?string
    {
        foreach ($orderResponse['links'] ?? [] as $link) {
            if (! is_array($link)) {
                continue;
            }

            $rel = strtolower((string) ($link['rel'] ?? ''));

            if (in_array($rel, ['approve', 'payer-action'], true)) {
                return isset($link['href']) ? (string) $link['href'] : null;
            }
        }

        return null;
    }

    /**
     * Build a pre-authenticated JSON request with timeouts and transient-failure
     * retries applied. Connection failures are retried twice before surfacing.
     */
    private function authenticatedRequest(): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->timeout((int) config('paypal.timeout'))
            ->connectTimeout((int) config('paypal.connect_timeout'))
            ->retry(2, 500, function (Throwable $exception): bool {
                return $exception instanceof ConnectionException;
            }, throw: false)
            ->acceptJson()
            ->asJson();
    }

    /**
     * Return a valid OAuth2 access token, fetching and caching a fresh one only
     * when the cache is empty.
     *
     * @throws PayPalException When the package is misconfigured or authentication fails.
     */
    private function accessToken(): string
    {
        $this->validateConfiguration();

        $cacheKey = $this->accessTokenCacheKey();

        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        [$token, $ttlInSeconds] = $this->requestAccessToken();

        Cache::put($cacheKey, $token, $ttlInSeconds);

        return $token;
    }

    /**
     * Request a fresh access token from PayPal using the client credentials grant.
     *
     * @return array{0: string, 1: int} Tuple of [access token, cache lifetime in seconds].
     *
     * @throws PayPalException When authentication fails or the response is malformed.
     */
    private function requestAccessToken(): array
    {
        $response = Http::withBasicAuth(
            (string) config('paypal.client_id'),
            (string) config('paypal.client_secret'),
        )
            ->timeout((int) config('paypal.timeout'))
            ->connectTimeout((int) config('paypal.connect_timeout'))
            ->asForm()
            ->post($this->endpoint('/v1/oauth2/token'), [
                'grant_type' => 'client_credentials',
            ]);

        if ($response->failed()) {
            throw PayPalException::requestFailed(
                message: $this->failureMessage($response, 'PayPal authentication failed. Check your PayPal credentials and base URL.'),
                details: $this->safeFailureDetails($response),
            );
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw PayPalException::requestFailed(
                message: 'PayPal authentication returned an invalid token.',
            );
        }

        return [$token, $this->resolveAccessTokenTtl($response->json('expires_in'))];
    }

    /**
     * Resolve how long, in seconds, the access token should be cached.
     *
     * PayPal reports the token lifetime in the OAuth2 `expires_in` field
     * (commonly several hours). Caching for that lifetime — minus a small safety
     * leeway so the token is refreshed just before PayPal expires it — keeps the
     * number of authentication round-trips to a minimum. When `expires_in` is
     * absent or invalid, the configured `access_token_ttl` (in minutes) is used.
     *
     * @param  mixed  $expiresIn  The raw `expires_in` value from PayPal, in seconds.
     */
    private function resolveAccessTokenTtl(mixed $expiresIn): int
    {
        $fallbackSeconds = max((int) config('paypal.access_token_ttl'), 1) * 60;

        if (! is_int($expiresIn) && ! (is_string($expiresIn) && ctype_digit($expiresIn))) {
            return $fallbackSeconds;
        }

        $leeway = max((int) config('paypal.access_token_leeway'), 0);

        return max((int) $expiresIn - $leeway, 1);
    }

    private function accessTokenCacheKey(): string
    {
        return (string) config('paypal.access_token_cache_key');
    }

    /**
     * Build an absolute API URL for the given path against the resolved base URL.
     *
     * @throws PayPalException When no valid base URL is configured.
     */
    private function endpoint(string $path): string
    {
        $base = $this->resolveBaseUrl();

        if ($base === '') {
            throw PayPalException::requestFailed(
                message: 'PayPal is not configured for a valid environment.',
                details: [
                    'base_url_configured' => false,
                ],
            );
        }

        return rtrim($base, '/') . $path;
    }

    /**
     * Resolve the API base URL, preferring an explicit `base_url` and otherwise
     * deriving it from the configured `mode` (`live` or `sandbox`).
     */
    private function resolveBaseUrl(): string
    {
        $baseUrl = config('paypal.base_url');

        if (is_string($baseUrl) && $baseUrl !== '') {
            return $baseUrl;
        }

        $mode = config('paypal.mode', 'sandbox');

        return match ($mode) {
            'live'  => 'https://api-m.paypal.com',
            default => 'https://api-m.sandbox.paypal.com',
        };
    }

    /**
     * Decode a successful PayPal response, or throw on failure.
     *
     * On a 401 the cached access token is discarded so the next call
     * re-authenticates instead of replaying a rejected token.
     *
     * @return array<string, mixed>
     *
     * @throws PayPalException When the response indicates a failure.
     */
    private function parseResponse(Response $response): array
    {
        if ($response->failed()) {
            if ($response->status() === 401) {
                Cache::forget($this->accessTokenCacheKey());
            }

            throw PayPalException::requestFailed(
                message: $this->failureMessage($response, 'PayPal payment request failed.'),
                details: $this->safeFailureDetails($response),
            );
        }

        /** @var array<string, mixed>|null $json */
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Guard against making requests with an invalid base URL or missing credentials.
     *
     * @throws PayPalException When required configuration is missing.
     */
    private function validateConfiguration(): void
    {
        if ($this->resolveBaseUrl() === '') {
            throw PayPalException::requestFailed(
                message: 'PayPal is not configured for a valid environment.',
                details: [
                    'base_url_configured' => false,
                ],
            );
        }

        if (! filled(config('paypal.client_id')) || ! filled(config('paypal.client_secret'))) {
            throw PayPalException::requestFailed(
                message: 'PayPal is not configured. Add PayPal credentials before making requests.',
                details: [
                    'client_id_configured'     => filled(config('paypal.client_id')),
                    'client_secret_configured' => filled(config('paypal.client_secret')),
                ],
            );
        }
    }

    /**
     * Extract a safe, scalar-only subset of PayPal error fields for exception
     * details, avoiding leaking arbitrary response payloads.
     *
     * @return array<string, mixed>
     */
    private function safeFailureDetails(Response $response): array
    {
        $body    = $response->json();
        $details = [
            'status' => $response->status(),
        ];

        if (! is_array($body)) {
            return $details;
        }

        foreach (['name', 'error', 'message', 'debug_id'] as $key) {
            if (isset($body[$key]) && is_scalar($body[$key])) {
                $details[$key] = (string) $body[$key];
            }
        }

        return $details;
    }

    /**
     * Derive a human-readable failure message, preferring PayPal's own
     * `message` field and falling back to the supplied default.
     */
    private function failureMessage(Response $response, string $fallback): string
    {
        if ($response->status() === 401) {
            return 'PayPal rejected the configured credentials. Verify the client ID and secret.';
        }

        $message = $response->json('message');

        return is_string($message) && $message !== '' ? $message : $fallback;
    }
}
