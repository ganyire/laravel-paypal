<?php

namespace LeonardGanyire\Paypal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LeonardGanyire\Paypal\Exceptions\PayPalException;
use Throwable;

final class PayPalClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
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
     * @return array<string, mixed>
     */
    public function captureOrder(string $orderId): array
    {
        $response = $this->authenticatedRequest()
            ->withBody('{}', 'application/json')
            ->post($this->endpoint("/v2/checkout/orders/{$orderId}/capture"));

        return $this->parseResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function authorizeOrder(string $orderId): array
    {
        $response = $this->authenticatedRequest()
            ->withBody('{}', 'application/json')
            ->post($this->endpoint("/v2/checkout/orders/{$orderId}/authorize"));

        return $this->parseResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId): array
    {
        $response = $this->authenticatedRequest()
            ->get($this->endpoint("/v2/checkout/orders/{$orderId}"));

        return $this->parseResponse($response);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
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
     * @param  array<string, mixed>  $payload
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
                'auth_algo' => $headers['paypal-auth-algo'] ?? null,
                'cert_url' => $headers['paypal-cert-url'] ?? null,
                'transmission_id' => $headers['paypal-transmission-id'] ?? null,
                'transmission_sig' => $headers['paypal-transmission-sig'] ?? null,
                'transmission_time' => $headers['paypal-transmission-time'] ?? null,
                'webhook_id' => $webhookId,
                'webhook_event' => $payload,
            ],
        );

        if ($response->failed()) {
            return false;
        }

        return ($response->json('verification_status') ?? '') === 'SUCCESS';
    }

    /**
     * @param  array<string, mixed>  $orderResponse
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

    private function accessToken(): string
    {
        $this->validateConfiguration();

        return Cache::remember(
            (string) config('paypal.access_token_cache_key'),
            now()->addMinutes((int) config('paypal.access_token_ttl')),
            function (): string {
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

                return $token;
            },
        );
    }

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

        return rtrim($base, '/').$path;
    }

    private function resolveBaseUrl(): string
    {
        $baseUrl = config('paypal.base_url');

        if (is_string($baseUrl) && $baseUrl !== '') {
            return $baseUrl;
        }

        $mode = config('paypal.mode', 'sandbox');

        return match ($mode) {
            'live' => 'https://api-m.paypal.com',
            default => 'https://api-m.sandbox.paypal.com',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function parseResponse(Response $response): array
    {
        if ($response->failed()) {
            if ($response->status() === 401) {
                Cache::forget((string) config('paypal.access_token_cache_key'));
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
                    'client_id_configured' => filled(config('paypal.client_id')),
                    'client_secret_configured' => filled(config('paypal.client_secret')),
                ],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function safeFailureDetails(Response $response): array
    {
        $body = $response->json();
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

    private function failureMessage(Response $response, string $fallback): string
    {
        if ($response->status() === 401) {
            return 'PayPal rejected the configured credentials. Verify the client ID and secret.';
        }

        $message = $response->json('message');

        return is_string($message) && $message !== '' ? $message : $fallback;
    }
}
