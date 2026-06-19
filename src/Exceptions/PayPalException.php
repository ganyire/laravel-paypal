<?php

namespace LeonardGanyire\Paypal\Exceptions;

use RuntimeException;
use Throwable;

final class PayPalException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        private readonly int $httpStatus = 502,
        private readonly string $errorCode = 'paypal_request_failed',
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function requestFailed(string $message, array $details = []): self
    {
        return new self(
            message: $message,
            details: $details,
        );
    }

    public function status(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
