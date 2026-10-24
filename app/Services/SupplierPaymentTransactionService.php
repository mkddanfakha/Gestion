<?php

namespace App\Services;

use App\Enums\PayableType;
use App\Enums\PaymentTransactionOrigin;
use App\Enums\PaymentTransactionStatus;
use App\Exceptions\InvalidPaymentTransactionException;
use App\Models\PaymentTransaction;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Enregistrement et annulation des règlements fournisseurs (factures).
 *
 * Source canonique du montant payé : somme des payment_transactions en statut posted
 * (payable_type = supplier_invoice). Les colonnes paid_amount, remaining_amount et
 * payment_status sont des agrégats recalculés après chaque règlement ou annulation.
 */
class SupplierPaymentTransactionService
{
    public const MONEY_EPSILON = 0.009;

    /**
     * @param  array{
     *     amount: float|int|string,
     *     payment_method: string,
     *     paid_at?: \DateTimeInterface|string|null,
     *     reference?: string|null,
     *     note?: string|null,
     *     idempotency_key?: string|null,
     *     origin?: PaymentTransactionOrigin,
     * }  $payload
     */
    public function recordForSupplierInvoice(int $supplierInvoiceId, User $user, array $payload): PaymentTransaction
    {
        return DB::transaction(function () use ($supplierInvoiceId, $user, $payload) {
            /** @var SupplierInvoice|null $invoice */
            $invoice = SupplierInvoice::query()->lockForUpdate()->find($supplierInvoiceId);

            if ($invoice === null) {
                throw new InvalidPaymentTransactionException('Facture fournisseur introuvable.');
            }

            $this->assertInvoiceAcceptsPayments($invoice);
            $this->assertPaymentLedgerAligned($invoice);

            $idempotencyKey = $payload['idempotency_key'] ?? null;
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = PaymentTransaction::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    if ($existing->payable_type === PayableType::SupplierInvoice
                        && (int) $existing->payable_id === $supplierInvoiceId
                        && $existing->status === PaymentTransactionStatus::Posted) {
                        return $existing;
                    }

                    throw new InvalidPaymentTransactionException('Clé d\'idempotence déjà utilisée pour un autre règlement.');
                }
            }

            $amount = round((float) $payload['amount'], 2);
            $paymentMethod = $payload['payment_method'] ?? '';

            $this->validateAmountAndMethod($amount, $paymentMethod);

            $postedTotal = $this->sumPostedAmount(PayableType::SupplierInvoice, $supplierInvoiceId);
            $totalAmount = round((float) $invoice->total_amount, 2);
            $remainingBefore = round($totalAmount - $postedTotal, 2);

            if ($amount > $remainingBefore + self::MONEY_EPSILON) {
                throw new InvalidPaymentTransactionException(
                    'Le montant dépasse le solde restant dû sur cette facture fournisseur.',
                );
            }

            if ($remainingBefore <= self::MONEY_EPSILON) {
                throw new InvalidPaymentTransactionException('Cette facture fournisseur est déjà entièrement réglée.');
            }

            $paidAt = $this->resolvePaidAt($payload['paid_at'] ?? null);

            $origin = $payload['origin'] ?? PaymentTransactionOrigin::Recorded;

            $transaction = PaymentTransaction::query()->create([
                'payable_type' => PayableType::SupplierInvoice,
                'payable_id' => $supplierInvoiceId,
                'amount' => $amount,
                'paid_at' => $paidAt,
                'payment_method' => $paymentMethod,
                'reference' => $payload['reference'] ?? null,
                'note' => $payload['note'] ?? null,
                'origin' => $origin,
                'user_id' => $user->id,
                'status' => PaymentTransactionStatus::Posted,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->syncSupplierInvoicePaymentAggregates($invoice);

            $transaction = $transaction->fresh();
            ActivityLogger::logSupplierPaymentRecorded($invoice->fresh(), $transaction, $user);

            return $transaction;
        });
    }

    public function voidTransaction(PaymentTransaction $transaction, User $user, string $voidReason): PaymentTransaction
    {
        if ($transaction->status === PaymentTransactionStatus::Voided) {
            throw new InvalidPaymentTransactionException('Ce règlement est déjà annulé.');
        }

        if ($transaction->payable_type !== PayableType::SupplierInvoice) {
            throw new InvalidPaymentTransactionException('Type de document non pris en charge pour l\'annulation.');
        }

        return DB::transaction(function () use ($transaction, $user, $voidReason) {
            /** @var SupplierInvoice|null $invoice */
            $invoice = SupplierInvoice::query()->lockForUpdate()->find($transaction->payable_id);

            if ($invoice === null) {
                throw new InvalidPaymentTransactionException('Facture fournisseur introuvable.');
            }

            $this->assertPaymentLedgerAligned($invoice);

            $transaction = PaymentTransaction::query()->lockForUpdate()->find($transaction->id);

            if ($transaction === null || $transaction->status === PaymentTransactionStatus::Voided) {
                throw new InvalidPaymentTransactionException('Ce règlement est déjà annulé.');
            }

            $transaction->update([
                'status' => PaymentTransactionStatus::Voided,
                'voided_at' => now(),
                'voided_by_user_id' => $user->id,
                'void_reason' => $voidReason,
            ]);

            $this->syncSupplierInvoicePaymentAggregates($invoice);

            return $transaction->fresh();
        });
    }

    public function assertPaymentLedgerAligned(SupplierInvoice $invoice): void
    {
        $posted = $this->sumPostedAmount(PayableType::SupplierInvoice, (int) $invoice->id);
        $paidAggregate = round((float) $invoice->paid_amount, 2);
        $remainingAggregate = round((float) $invoice->remaining_amount, 2);
        $totalAmount = round((float) $invoice->total_amount, 2);
        $expectedRemaining = round($totalAmount - $posted, 2);

        if (abs($paidAggregate - $posted) > self::MONEY_EPSILON) {
            throw new InvalidPaymentTransactionException(
                'Les règlements enregistrés ne correspondent pas au montant payé affiché sur la facture fournisseur. Contactez un administrateur.',
            );
        }

        if (abs($remainingAggregate - $expectedRemaining) > self::MONEY_EPSILON) {
            throw new InvalidPaymentTransactionException(
                'Les règlements enregistrés ne correspondent pas au solde restant affiché sur la facture fournisseur. Contactez un administrateur.',
            );
        }
    }

    public function sumPostedAmount(PayableType $payableType, int $payableId): float
    {
        $sum = PaymentTransaction::query()
            ->where('payable_type', $payableType)
            ->where('payable_id', $payableId)
            ->where('status', PaymentTransactionStatus::Posted)
            ->sum('amount');

        return round((float) $sum, 2);
    }

    public function syncSupplierInvoicePaymentAggregates(SupplierInvoice $invoice): void
    {
        $totalAmount = round((float) $invoice->total_amount, 2);
        $postedTotal = $this->sumPostedAmount(PayableType::SupplierInvoice, (int) $invoice->id);

        // calculatePaymentState() est une fonction pure (total, montant réglé) — le 2e paramètre
        // est le cumul posted, indépendamment du modèle Sale.
        $state = SalePaymentService::calculatePaymentState($totalAmount, $postedTotal);

        $invoice->forceFill([
            'paid_amount' => $state['down_payment_amount'],
            'remaining_amount' => $state['remaining_amount'],
            'payment_status' => $state['payment_status'],
        ])->save();
    }

    private function assertInvoiceAcceptsPayments(SupplierInvoice $invoice): void
    {
        if ($invoice->status === SupplierInvoice::STATUS_CANCELLED) {
            throw new InvalidPaymentTransactionException('Impossible d\'enregistrer un règlement sur une facture fournisseur annulée.');
        }

        if ($invoice->status !== SupplierInvoice::STATUS_OPEN) {
            throw new InvalidPaymentTransactionException('Seules les factures fournisseurs ouvertes acceptent un règlement.');
        }

        if (round((float) $invoice->total_amount, 2) <= 0) {
            throw new InvalidPaymentTransactionException('Cette facture fournisseur n\'a pas de montant à régler.');
        }
    }

    private function resolvePaidAt(mixed $paidAt): \Illuminate\Support\Carbon
    {
        if ($paidAt === null || $paidAt === '') {
            return now('UTC');
        }

        return \Illuminate\Support\Carbon::parse($paidAt, 'UTC');
    }

    private function validateAmountAndMethod(float $amount, string $paymentMethod): void
    {
        if ($amount <= 0) {
            throw new InvalidPaymentTransactionException('Le montant du règlement doit être strictement positif.');
        }

        if (! in_array($paymentMethod, PaymentTransaction::PAYMENT_METHODS, true)) {
            throw new InvalidPaymentTransactionException('Mode de paiement invalide.');
        }
    }
}
