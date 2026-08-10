<?php

use App\Services\SalePaymentService;

test('payment status is pending when no payment and total is positive', function () {
    $state = SalePaymentService::calculatePaymentState(100000, 0);

    expect($state['payment_status'])->toBe('pending')
        ->and($state['down_payment_amount'])->toBe(0.0)
        ->and($state['remaining_amount'])->toBe(100000.0);
});

test('payment status is partial when payment is below total', function () {
    $state = SalePaymentService::calculatePaymentState(100000, 30000);

    expect($state['payment_status'])->toBe('partial')
        ->and($state['down_payment_amount'])->toBe(30000.0)
        ->and($state['remaining_amount'])->toBe(70000.0);
});

test('payment status is paid when payment equals total', function () {
    $state = SalePaymentService::calculatePaymentState(100000, 100000);

    expect($state['payment_status'])->toBe('paid')
        ->and($state['down_payment_amount'])->toBe(100000.0)
        ->and($state['remaining_amount'])->toBe(0.0);
});

test('payment above total is capped and marked as paid', function () {
    $state = SalePaymentService::calculatePaymentState(100000, 150000);

    expect($state['payment_status'])->toBe('paid')
        ->and($state['down_payment_amount'])->toBe(100000.0)
        ->and($state['remaining_amount'])->toBe(0.0);
});

test('explicit paid status forces full payment', function () {
    $state = SalePaymentService::calculatePaymentState(100000, 0, SalePaymentService::STATUS_PAID);

    expect($state['payment_status'])->toBe('paid')
        ->and($state['down_payment_amount'])->toBe(100000.0)
        ->and($state['remaining_amount'])->toBe(0.0);
});

test('negative payment amount is treated as zero', function () {
    $state = SalePaymentService::calculatePaymentState(100000, -5000);

    expect($state['payment_status'])->toBe('pending')
        ->and($state['down_payment_amount'])->toBe(0.0)
        ->and($state['remaining_amount'])->toBe(100000.0);
});

test('payment method is required when down payment is positive', function () {
    expect(SalePaymentService::paymentMethodValidationMessage(null, 30000))
        ->toBe('Veuillez sélectionner un mode de paiement.');
});

test('payment method is optional when down payment is zero', function () {
    expect(SalePaymentService::paymentMethodValidationMessage(null, 0))->toBeNull();
});
