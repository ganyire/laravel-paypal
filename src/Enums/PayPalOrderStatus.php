<?php

namespace LeonardGanyire\Paypal\Enums;

enum PayPalOrderStatus: string
{
    case Created = 'CREATED';
    case Saved = 'SAVED';
    case Approved = 'APPROVED';
    case PayerActionRequired = 'PAYER_ACTION_REQUIRED';
    case Voided = 'VOIDED';
    case Completed = 'COMPLETED';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromResponse(array $payload): self
    {
        $status = strtoupper((string) ($payload['status'] ?? ''));

        return self::tryFrom($status) ?? self::Created;
    }

    public function isCompleted(): bool
    {
        return $this === self::Completed;
    }

    public function isCancelled(): bool
    {
        return in_array($this, [self::Voided], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function captureReference(array $payload): ?string
    {
        foreach ($payload['purchase_units'] ?? [] as $unit) {
            if (! is_array($unit)) {
                continue;
            }

            $payments = $unit['payments']['captures'] ?? [];

            if (! is_array($payments)) {
                continue;
            }

            foreach ($payments as $capture) {
                if (is_array($capture) && isset($capture['id'])) {
                    return (string) $capture['id'];
                }
            }
        }

        return isset($payload['id']) ? (string) $payload['id'] : null;
    }
}
