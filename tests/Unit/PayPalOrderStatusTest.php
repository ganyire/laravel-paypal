<?php

use LeonardGanyire\Paypal\Enums\PayPalOrderStatus;

it('maps completed capture responses', function () {
    $status = PayPalOrderStatus::fromResponse(['status' => 'COMPLETED']);

    expect($status)->toBe(PayPalOrderStatus::Completed);
    expect($status->isCompleted())->toBeTrue();
    expect($status->isCancelled())->toBeFalse();
});

it('maps voided capture responses', function () {
    $status = PayPalOrderStatus::fromResponse(['status' => 'VOIDED']);

    expect($status)->toBe(PayPalOrderStatus::Voided);
    expect($status->isCancelled())->toBeTrue();
});

it('defaults unknown statuses to created', function () {
    $status = PayPalOrderStatus::fromResponse(['status' => 'UNKNOWN']);

    expect($status)->toBe(PayPalOrderStatus::Created);
});

it('extracts capture reference from nested purchase units', function () {
    $reference = PayPalOrderStatus::captureReference([
        'id' => 'PAYPAL-ORDER-1',
        'purchase_units' => [[
            'payments' => [
                'captures' => [[
                    'id' => 'CAPTURE-123',
                ]],
            ],
        ]],
    ]);

    expect($reference)->toBe('CAPTURE-123');
});

it('falls back to order id when capture reference is missing', function () {
    $reference = PayPalOrderStatus::captureReference([
        'id' => 'PAYPAL-ORDER-1',
    ]);

    expect($reference)->toBe('PAYPAL-ORDER-1');
});
