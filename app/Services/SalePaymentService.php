<?php

namespace App\Services;

class SalePaymentService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public static function calculatePaymentState(
        float $totalAmount,
        float $downPaymentAmount,
        ?string $explicitStatus = null,
    ): array {
        $downPaymentAmount = max(0, $downPaymentAmount);

        if ($explicitStatus === self::STATUS_PAID) {
            return [
                'down_payment_amount' => $totalAmount,
                'remaining_amount' => 0.0,
                'payment_status' => self::STATUS_PAID,
            ];
        }

        if ($downPaymentAmount >= $totalAmount && $totalAmount > 0) {
            return [
                'down_payment_amount' => $totalAmount,
                'remaining_amount' => 0.0,
                'payment_status' => self::STATUS_PAID,
            ];
        }

        $remainingAmount = $totalAmount - $downPaymentAmount;

        if ($downPaymentAmount > 0 && $remainingAmount > 0) {
            return [
                'down_payment_amount' => $downPaymentAmount,
                'remaining_amount' => $remainingAmount,
                'payment_status' => self::STATUS_PARTIAL,
            ];
        }

        if ($downPaymentAmount == 0 && $totalAmount > 0) {
            return [
                'down_payment_amount' => 0.0,
                'remaining_amount' => $totalAmount,
                'payment_status' => self::STATUS_PENDING,
            ];
        }

        return [
            'down_payment_amount' => $downPaymentAmount,
            'remaining_amount' => max(0.0, $remainingAmount),
            'payment_status' => $explicitStatus ?? self::STATUS_PAID,
        ];
    }

    public static function paymentMethodValidationMessage(?string $paymentMethod, float $downPaymentAmount): ?string
    {
        if ($downPaymentAmount > 0 && empty($paymentMethod)) {
            return 'Veuillez sélectionner un mode de paiement.';
        }

        return null;
    }

    public static function resolvePaymentMethod(?string $paymentMethod, float $downPaymentAmount): ?string
    {
        if ($downPaymentAmount <= 0 && empty($paymentMethod)) {
            return null;
        }

        return $paymentMethod;
    }
}
