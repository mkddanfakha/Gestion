<?php

namespace App\Services\Treasury;

use App\Enums\PayableType;
use App\Enums\PaymentTransactionOrigin;
use App\Enums\PaymentTransactionStatus;
use App\Models\Expense;
use App\Models\PaymentTransaction;
use App\Models\Sale;
use App\Models\SupplierInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lecture agrégée de trésorerie à partir de payment_transactions (ventes et factures fournisseurs,
 * posted) et expenses.
 *
 * - Les paiements voided sont exclus des totaux (lignes conservées en base).
 * - Seules les transactions posted comptent ; une ligne payment_transaction = un mouvement (clé unique).
 * - Règlement fournisseur (supplier_invoice) = sortie de trésorerie ; la dette documentaire n'est
 *   pas un mouvement. Les dépenses (Expense) restent des sorties distinctes : un règlement
 *   fournisseur ne crée pas et ne remplace pas une Expense.
 * - Le solde net calculé n'est pas un solde bancaire ou de caisse physique certifié.
 *
 * Pour l'exposition HTTP, utiliser {@see AuthorizedTreasuryReadService} avec un {@see TreasuryQueryScope}.
 */
final class TreasuryReadService
{
    public function __construct(
        private PaymentMethodNormalizer $paymentMethodNormalizer,
    ) {}

    public function report(TreasuryReadFilter $filter, TreasuryQueryScope $scope): TreasuryReport
    {
        $movements = $this->collectMovements($filter, $scope);

        return new TreasuryReport(
            movements: $movements,
            periodTotals: $this->periodTotalsFromMovements($movements),
            totalsByPaymentMethod: $this->totalsByPaymentMethodFromMovements($movements),
        );
    }

    /**
     * @return list<TreasuryMovement>
     */
    public function movements(TreasuryReadFilter $filter, ?TreasuryQueryScope $scope = null): array
    {
        return $this->collectMovements($filter, $scope);
    }

    public function periodTotals(TreasuryReadFilter $filter, ?TreasuryQueryScope $scope = null): TreasuryPeriodTotals
    {
        return $this->periodTotalsFromMovements($this->collectMovements($filter, $scope));
    }

    /**
     * @return array<string, array{inflows: float, outflows: float, net: float, known: bool}>
     */
    public function totalsByPaymentMethod(TreasuryReadFilter $filter, ?TreasuryQueryScope $scope = null): array
    {
        return $this->totalsByPaymentMethodFromMovements($this->collectMovements($filter, $scope));
    }

    /**
     * @return list<TreasuryMovement>
     */
    private function collectMovements(TreasuryReadFilter $filter, ?TreasuryQueryScope $scope = null): array
    {
        $paymentMovements = $this->salePaymentMovements($filter, $scope);
        $supplierPaymentMovements = $this->supplierInvoicePaymentMovements($filter, $scope);
        $expenseMovements = $this->expenseMovements($filter, $scope);

        return collect($paymentMovements)
            ->merge($supplierPaymentMovements)
            ->merge($expenseMovements)
            ->sortBy(fn (TreasuryMovement $movement): int => $movement->occurredAt->getTimestamp())
            ->values()
            ->all();
    }

    /**
     * @param  list<TreasuryMovement>  $movements
     */
    private function periodTotalsFromMovements(array $movements): TreasuryPeriodTotals
    {
        $inflows = 0.0;
        $outflows = 0.0;

        foreach ($movements as $movement) {
            if ($movement->amount > 0) {
                $inflows += $movement->amount;
            } elseif ($movement->amount < 0) {
                $outflows += abs($movement->amount);
            }
        }

        $inflows = round($inflows, 2);
        $outflows = round($outflows, 2);

        return new TreasuryPeriodTotals(
            totalInflows: $inflows,
            totalOutflows: $outflows,
            net: round($inflows - $outflows, 2),
        );
    }

    /**
     * @param  list<TreasuryMovement>  $movements
     * @return array<string, array{inflows: float, outflows: float, net: float, known: bool}>
     */
    private function totalsByPaymentMethodFromMovements(array $movements): array
    {
        $byMethod = [];

        foreach ($movements as $movement) {
            $key = $movement->paymentMethodCanonical;

            if (! isset($byMethod[$key])) {
                $byMethod[$key] = [
                    'inflows' => 0.0,
                    'outflows' => 0.0,
                    'net' => 0.0,
                    'known' => $movement->paymentMethodKnown,
                ];
            }

            if ($movement->amount > 0) {
                $byMethod[$key]['inflows'] += $movement->amount;
            } elseif ($movement->amount < 0) {
                $byMethod[$key]['outflows'] += abs($movement->amount);
            }

            $byMethod[$key]['known'] = $byMethod[$key]['known'] && $movement->paymentMethodKnown;
        }

        foreach ($byMethod as $method => $row) {
            $byMethod[$method]['inflows'] = round($row['inflows'], 2);
            $byMethod[$method]['outflows'] = round($row['outflows'], 2);
            $byMethod[$method]['net'] = round($row['inflows'] - $row['outflows'], 2);
        }

        ksort($byMethod);

        return $byMethod;
    }

    /**
     * @return list<TreasuryMovement>
     */
    private function salePaymentMovements(TreasuryReadFilter $filter, ?TreasuryQueryScope $scope = null): array
    {
        if ($scope !== null && ! $scope->includeSalePayments) {
            return [];
        }

        $query = PaymentTransaction::query()
            ->where('payable_type', PayableType::Sale)
            ->where('status', PaymentTransactionStatus::Posted)
            ->whereBetween('paid_at', [
                $filter->from->copy()->startOfSecond(),
                $filter->to->copy()->endOfSecond(),
            ])
            ->orderBy('paid_at')
            ->orderBy('id');

        if ($scope !== null) {
            $query->whereIn(
                'payable_id',
                Sale::query()->visibleTo($scope->user)->select('id'),
            );
        }

        if ($filter->paymentMethodCanonical !== null && $filter->paymentMethodCanonical !== '') {
            $rawValues = $this->paymentMethodNormalizer->rawValuesMatchingCanonical(
                $filter->paymentMethodCanonical,
            );

            if ($rawValues === []) {
                return [];
            }

            $query->whereIn('payment_method', $rawValues);
        }

        /** @var Collection<int, PaymentTransaction> $payments */
        $payments = $query->get();

        if ($payments->isEmpty()) {
            return [];
        }

        $saleNumbers = Sale::query()
            ->whereIn('id', $payments->pluck('payable_id')->unique()->all())
            ->pluck('sale_number', 'id');

        $movements = [];

        foreach ($payments as $payment) {
            $normalized = $this->paymentMethodNormalizer->normalize($payment->payment_method);
            $origin = $payment->origin instanceof PaymentTransactionOrigin
                ? $payment->origin->value
                : (string) $payment->origin;

            $saleId = (int) $payment->payable_id;
            $saleNumber = (string) ($saleNumbers[$saleId] ?? $saleId);

            $movements[] = new TreasuryMovement(
                movementKey: 'sale_payment:'.$payment->id,
                sourceType: TreasuryMovement::SOURCE_SALE_PAYMENT,
                sourceId: (int) $payment->id,
                occurredAt: $payment->paid_at ?? Carbon::parse($payment->created_at),
                label: 'Encaissement vente '.$saleNumber,
                paymentMethodRaw: $normalized->raw,
                paymentMethodCanonical: $normalized->canonical,
                paymentMethodKnown: $normalized->known,
                amount: round((float) $payment->amount, 2),
                paymentOrigin: $origin,
                isHistoricalBackfill: $payment->origin === PaymentTransactionOrigin::HistoricalBackfill,
                saleId: $saleId,
            );
        }

        return $movements;
    }

    /**
     * Sorties trésorerie : règlements posted sur factures fournisseurs (une ligne ledger = un mouvement).
     *
     * @return list<TreasuryMovement>
     */
    private function supplierInvoicePaymentMovements(TreasuryReadFilter $filter, ?TreasuryQueryScope $scope = null): array
    {
        if ($scope !== null && ! $scope->includeSupplierInvoicePayments) {
            return [];
        }

        $query = PaymentTransaction::query()
            ->where('payable_type', PayableType::SupplierInvoice)
            ->where('status', PaymentTransactionStatus::Posted)
            ->whereBetween('paid_at', [
                $filter->from->copy()->startOfSecond(),
                $filter->to->copy()->endOfSecond(),
            ])
            ->orderBy('paid_at')
            ->orderBy('id');

        if ($filter->paymentMethodCanonical !== null && $filter->paymentMethodCanonical !== '') {
            $rawValues = $this->paymentMethodNormalizer->rawValuesMatchingCanonical(
                $filter->paymentMethodCanonical,
            );

            if ($rawValues === []) {
                return [];
            }

            $query->whereIn('payment_method', $rawValues);
        }

        /** @var Collection<int, PaymentTransaction> $payments */
        $payments = $query->get();

        if ($payments->isEmpty()) {
            return [];
        }

        $invoiceIds = $payments->pluck('payable_id')->unique()->all();

        $invoices = SupplierInvoice::query()
            ->with('supplier:id,name')
            ->whereIn('id', $invoiceIds)
            ->get()
            ->keyBy('id');

        $movements = [];

        foreach ($payments as $payment) {
            $normalized = $this->paymentMethodNormalizer->normalize($payment->payment_method);
            $origin = $payment->origin instanceof PaymentTransactionOrigin
                ? $payment->origin->value
                : (string) $payment->origin;

            $invoiceId = (int) $payment->payable_id;
            /** @var SupplierInvoice|null $invoice */
            $invoice = $invoices->get($invoiceId);
            $invoiceNumber = $invoice?->invoice_number ?? (string) $invoiceId;
            $supplierName = $invoice?->supplier?->name;

            $label = $supplierName !== null && $supplierName !== ''
                ? 'Règlement fournisseur '.$supplierName.' ('.$invoiceNumber.')'
                : 'Règlement facture fournisseur '.$invoiceNumber;

            $movements[] = new TreasuryMovement(
                movementKey: 'supplier_invoice_payment:'.$payment->id,
                sourceType: TreasuryMovement::SOURCE_SUPPLIER_INVOICE_PAYMENT,
                sourceId: (int) $payment->id,
                occurredAt: $payment->paid_at ?? Carbon::parse($payment->created_at),
                label: $label,
                paymentMethodRaw: $normalized->raw,
                paymentMethodCanonical: $normalized->canonical,
                paymentMethodKnown: $normalized->known,
                amount: round(-1 * (float) $payment->amount, 2),
                paymentOrigin: $origin,
                isHistoricalBackfill: $payment->origin === PaymentTransactionOrigin::HistoricalBackfill,
                saleId: null,
                supplierInvoiceId: $invoice !== null ? $invoiceId : null,
                supplierInvoiceNumber: $invoice !== null ? $invoiceNumber : null,
                supplierName: $supplierName,
            );
        }

        return $movements;
    }

    /**
     * @return list<TreasuryMovement>
     */
    private function expenseMovements(TreasuryReadFilter $filter, ?TreasuryQueryScope $scope = null): array
    {
        if ($scope !== null && ! $scope->includeExpenses) {
            return [];
        }

        $fromDate = $filter->from->toDateString();
        $toDate = $filter->to->toDateString();

        $query = Expense::query()
            ->whereBetween('expense_date', [$fromDate, $toDate])
            ->orderBy('expense_date')
            ->orderBy('id');

        if ($scope !== null) {
            $query->visibleTo($scope->user);
        }

        if ($filter->paymentMethodCanonical !== null && $filter->paymentMethodCanonical !== '') {
            $rawValues = $this->paymentMethodNormalizer->rawValuesMatchingCanonical(
                $filter->paymentMethodCanonical,
            );

            if ($rawValues === []) {
                return [];
            }

            $query->whereIn('payment_method', $rawValues);
        }

        $movements = [];

        foreach ($query->get() as $expense) {
            $normalized = $this->paymentMethodNormalizer->normalize($expense->payment_method);
            $occurredAt = $expense->expense_date instanceof Carbon
                ? $expense->expense_date->copy()->startOfDay()
                : Carbon::parse($expense->expense_date)->startOfDay();

            $movements[] = new TreasuryMovement(
                movementKey: 'expense:'.$expense->id,
                sourceType: TreasuryMovement::SOURCE_EXPENSE,
                sourceId: (int) $expense->id,
                occurredAt: $occurredAt,
                label: 'Dépense '.$expense->title,
                paymentMethodRaw: $normalized->raw,
                paymentMethodCanonical: $normalized->canonical,
                paymentMethodKnown: $normalized->known,
                amount: round(-1 * (float) $expense->amount, 2),
                paymentOrigin: null,
                isHistoricalBackfill: false,
                saleId: null,
            );
        }

        return $movements;
    }
}
