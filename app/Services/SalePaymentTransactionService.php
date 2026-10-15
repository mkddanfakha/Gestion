<?php

namespace App\Services;

use App\Enums\PayableType;
use App\Enums\PaymentTransactionOrigin;
use App\Enums\PaymentTransactionStatus;
use App\Exceptions\InvalidPaymentTransactionException;
use App\Models\PaymentTransaction;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Enregistrement et annulation des règlements clients (ventes).
 *
 * Source canonique du montant payé : somme des payment_transactions en statut posted.
 * Les colonnes sales.down_payment_amount, remaining_amount et payment_status sont des
 * agrégats de compatibilité recalculés ici après chaque règlement ou annulation.
 *
 * Ventes historiques : tant que down_payment_amount > somme posted, l'encaissement et
 * la modification de vente sont refusés jusqu'à reprise (payments:backfill-sale-down-payments).
 *
 * sales.payment_method : dernier règlement posted (paid_at desc, id desc) lorsqu'il
 * existe au moins un règlement posted ; sinon la valeur existante sur la vente est conservée.
 */
class SalePaymentTransactionService
{
    public const MONEY_EPSILON = 0.009;

    public const HISTORICAL_BACKFILL_NOTE = 'Reprise historique : date de substitution (sale_date ou updated_at), pas une date d\'encaissement vérifiée.';

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
    public function recordForSale(int $saleId, User $user, array $payload): PaymentTransaction
    {
        return DB::transaction(function () use ($saleId, $user, $payload) {
            /** @var Sale $sale */
            $sale = Sale::query()->lockForUpdate()->find($saleId);

            if ($sale === null) {
                throw new InvalidPaymentTransactionException('Vente introuvable.');
            }

            $this->assertSaleAcceptsPayments($sale);
            $this->assertPaymentLedgerAligned($sale);

            $idempotencyKey = $payload['idempotency_key'] ?? null;
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = PaymentTransaction::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    if ($existing->payable_type === PayableType::Sale
                        && (int) $existing->payable_id === $saleId
                        && $existing->status === PaymentTransactionStatus::Posted) {
                        return $existing;
                    }

                    throw new InvalidPaymentTransactionException('Clé d\'idempotence déjà utilisée pour un autre règlement.');
                }
            }

            $amount = round((float) $payload['amount'], 2);
            $paymentMethod = $payload['payment_method'] ?? '';

            $this->validateAmountAndMethod($amount, $paymentMethod);

            $postedTotal = $this->sumPostedAmount(PayableType::Sale, $saleId);
            $totalAmount = round((float) $sale->total_amount, 2);
            $remainingBefore = round($totalAmount - $postedTotal, 2);

            if ($amount > $remainingBefore + self::MONEY_EPSILON) {
                throw new InvalidPaymentTransactionException(
                    'Le montant dépasse le solde restant dû sur cette vente.',
                );
            }

            if ($remainingBefore <= self::MONEY_EPSILON) {
                throw new InvalidPaymentTransactionException('Cette vente est déjà entièrement réglée.');
            }

            $paidAt = isset($payload['paid_at'])
                ? \Illuminate\Support\Carbon::parse($payload['paid_at'])
                : now();

            $origin = $payload['origin'] ?? PaymentTransactionOrigin::Recorded;

            $transaction = PaymentTransaction::query()->create([
                'payable_type' => PayableType::Sale,
                'payable_id' => $saleId,
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

            $this->syncSalePaymentAggregates($sale);

            return $transaction->fresh();
        });
    }

    public function voidTransaction(PaymentTransaction $transaction, User $user, string $voidReason): PaymentTransaction
    {
        if ($transaction->status === PaymentTransactionStatus::Voided) {
            throw new InvalidPaymentTransactionException('Ce règlement est déjà annulé.');
        }

        if ($transaction->payable_type !== PayableType::Sale) {
            throw new InvalidPaymentTransactionException('Type de document non pris en charge pour l\'annulation.');
        }

        return DB::transaction(function () use ($transaction, $user, $voidReason) {
            /** @var Sale $sale */
            $sale = Sale::query()->lockForUpdate()->find($transaction->payable_id);

            if ($sale === null) {
                throw new InvalidPaymentTransactionException('Vente introuvable.');
            }

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

            $this->syncSalePaymentAggregates($sale);

            return $transaction->fresh();
        });
    }

    /**
     * Acompte initial à la création de vente (une seule transaction, atomique avec la vente).
     */
    public function recordInitialDownPaymentForNewSale(
        Sale $sale,
        User $user,
        float $amount,
        string $paymentMethod,
    ): ?PaymentTransaction {
        $amount = round($amount, 2);

        if ($amount <= self::MONEY_EPSILON) {
            $this->syncSalePaymentAggregates($sale);

            return null;
        }

        $this->validateAmountAndMethod($amount, $paymentMethod);

        return DB::transaction(function () use ($sale, $user, $amount, $paymentMethod) {
            /** @var Sale $lockedSale */
            $lockedSale = Sale::query()->lockForUpdate()->find($sale->id);

            if ($lockedSale === null) {
                throw new InvalidPaymentTransactionException('Vente introuvable.');
            }

            if ($this->sumPostedAmount(PayableType::Sale, (int) $lockedSale->id) > self::MONEY_EPSILON) {
                throw new InvalidPaymentTransactionException('Un acompte initial a déjà été enregistré pour cette vente.');
            }

            $totalAmount = round((float) $lockedSale->total_amount, 2);

            if ($amount > $totalAmount + self::MONEY_EPSILON) {
                throw new InvalidPaymentTransactionException(
                    'L\'acompte initial ne peut pas dépasser le total de la vente.',
                );
            }

            $transaction = PaymentTransaction::query()->create([
                'payable_type' => PayableType::Sale,
                'payable_id' => $lockedSale->id,
                'amount' => $amount,
                'paid_at' => now(),
                'payment_method' => $paymentMethod,
                'origin' => PaymentTransactionOrigin::Recorded,
                'user_id' => $user->id,
                'status' => PaymentTransactionStatus::Posted,
                'idempotency_key' => 'initial-payment:sale:'.$lockedSale->id,
            ]);

            $this->syncSalePaymentAggregates($lockedSale);

            return $transaction->fresh();
        });
    }

    /**
     * Reprise historique : crée la ligne initial sans passer par assertPaymentLedgerAligned.
     */
    public function recordHistoricalBackfillForSale(
        Sale $sale,
        User $user,
        float $amount,
        string $paymentMethod,
        \DateTimeInterface $substitutionDate,
    ): PaymentTransaction {
        return DB::transaction(function () use ($sale, $user, $amount, $paymentMethod, $substitutionDate) {
            /** @var Sale $lockedSale */
            $lockedSale = Sale::query()->lockForUpdate()->find($sale->id);

            if ($lockedSale === null) {
                throw new InvalidPaymentTransactionException('Vente introuvable.');
            }

            $this->validateAmountAndMethod($amount, $paymentMethod);

            $transaction = PaymentTransaction::query()->create([
                'payable_type' => PayableType::Sale,
                'payable_id' => $lockedSale->id,
                'amount' => round($amount, 2),
                'paid_at' => $substitutionDate,
                'payment_method' => $paymentMethod,
                'note' => self::HISTORICAL_BACKFILL_NOTE,
                'origin' => PaymentTransactionOrigin::HistoricalBackfill,
                'user_id' => $user->id,
                'status' => PaymentTransactionStatus::Posted,
                'idempotency_key' => 'historical-backfill:sale:'.$lockedSale->id,
            ]);

            $this->syncSalePaymentAggregates($lockedSale);

            return $transaction->fresh();
        });
    }

    public function assertPaymentLedgerAligned(Sale $sale): void
    {
        $posted = $this->sumPostedAmount(PayableType::Sale, (int) $sale->id);
        $legacyDown = round((float) $sale->down_payment_amount, 2);

        if ($posted <= self::MONEY_EPSILON && $legacyDown > self::MONEY_EPSILON) {
            throw new InvalidPaymentTransactionException(
                'Cette vente contient un acompte historique non migré. Exécutez la reprise des règlements (payments:backfill-sale-down-payments) avant tout encaissement.',
            );
        }

        if ($posted > self::MONEY_EPSILON && abs($legacyDown - $posted) > self::MONEY_EPSILON) {
            throw new InvalidPaymentTransactionException(
                'Les règlements enregistrés ne correspondent pas au solde affiché de la vente. Corrigez la reprise ou contactez un administrateur.',
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

    public function syncSalePaymentAggregates(Sale $sale): void
    {
        $totalAmount = round((float) $sale->total_amount, 2);
        $postedTotal = $this->sumPostedAmount(PayableType::Sale, (int) $sale->id);

        $state = SalePaymentService::calculatePaymentState($totalAmount, $postedTotal);

        $lastMethod = PaymentTransaction::query()
            ->where('payable_type', PayableType::Sale)
            ->where('payable_id', $sale->id)
            ->where('status', PaymentTransactionStatus::Posted)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->value('payment_method');

        $paymentMethod = $lastMethod ?? $sale->payment_method;

        $sale->update([
            'down_payment_amount' => $state['down_payment_amount'],
            'remaining_amount' => $state['remaining_amount'],
            'payment_status' => $state['payment_status'],
            'payment_method' => SalePaymentService::resolvePaymentMethod(
                $paymentMethod,
                $state['down_payment_amount'],
            ),
        ]);
    }

    private function assertSaleAcceptsPayments(Sale $sale): void
    {
        if ($sale->status === 'cancelled') {
            throw new InvalidPaymentTransactionException('Impossible d\'enregistrer un règlement sur une vente annulée.');
        }

        if (round((float) $sale->total_amount, 2) <= 0) {
            throw new InvalidPaymentTransactionException('Cette vente n\'a pas de montant à régler.');
        }
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
