<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class CustomerCrmService
{
    /**
     * Base des ventes commerciales comptabilisées dans les KPI client.
     * Règle : ventes non annulées rattachées au client (hors devis).
     */
    private function salesQuery(Customer $customer, ?User $user): Builder
    {
        return Sale::query()
            ->where('customer_id', $customer->id)
            ->where('status', '!=', 'cancelled')
            ->visibleTo($user);
    }

    /**
     * @return array{
     *     orders_count: int,
     *     total_purchased: float,
     *     total_paid: float,
     *     remaining_balance: float,
     *     unpaid_invoices_count: int,
     *     pending_quotes_count: int,
     *     last_sale_at: ?string,
     *     last_visit_at: null
     * }
     */
    public function buildSummary(Customer $customer, ?User $user): array
    {
        $aggregates = (clone $this->salesQuery($customer, $user))
            ->selectRaw('
                COUNT(*) as orders_count,
                COALESCE(SUM(total_amount), 0) as total_purchased,
                COALESCE(SUM(CASE WHEN payment_status = ? THEN total_amount ELSE down_payment_amount END), 0) as total_paid,
                COALESCE(SUM(remaining_amount), 0) as remaining_balance,
                MAX(created_at) as last_sale_at
            ', ['paid'])
            ->first();

        // payment_status values are string enums: paid, partial, pending
        $unpaidInvoicesCount = (clone $this->salesQuery($customer, $user))
            ->whereIn('payment_status', ['pending', 'partial'])
            ->count();

        $pendingQuotesCount = Quote::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['draft', 'sent'])
            ->count();

        return [
            'orders_count' => (int) ($aggregates->orders_count ?? 0),
            'total_purchased' => (float) ($aggregates->total_purchased ?? 0),
            'total_paid' => (float) ($aggregates->total_paid ?? 0),
            'remaining_balance' => (float) ($aggregates->remaining_balance ?? 0),
            'unpaid_invoices_count' => $unpaidInvoicesCount,
            'pending_quotes_count' => $pendingQuotesCount,
            'last_sale_at' => $aggregates?->last_sale_at
                ? Carbon::parse($aggregates->last_sale_at)->toIso8601String()
                : null,
            'last_visit_at' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function unpaidInvoices(Customer $customer, ?User $user, int $limit = 10): array
    {
        return (clone $this->salesQuery($customer, $user))
            ->whereIn('payment_status', ['pending', 'partial'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get([
                'id',
                'sale_number',
                'total_amount',
                'remaining_amount',
                'due_date',
                'payment_status',
                'created_at',
            ])
            ->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'total_amount' => (float) $sale->total_amount,
                'remaining_amount' => (float) $sale->remaining_amount,
                'due_date' => $sale->due_date?->toDateString(),
                'payment_status' => $sale->payment_status,
                'created_at' => $sale->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingQuotes(Customer $customer, int $limit = 10): array
    {
        return Quote::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['draft', 'sent'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'quote_number', 'total_amount', 'status', 'created_at', 'valid_until'])
            ->map(fn (Quote $quote) => [
                'id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'total_amount' => (float) $quote->total_amount,
                'status' => $quote->status,
                'created_at' => $quote->created_at?->toIso8601String(),
                'valid_until' => $quote->valid_until?->toDateString(),
            ])
            ->all();
    }

    public function paginateSales(Customer $customer, ?User $user, int $page = 1, int $perPage = 10): LengthAwarePaginator
    {
        return (clone $this->salesQuery($customer, $user))
            ->orderByDesc('created_at')
            ->paginate($perPage, [
                'id',
                'sale_number',
                'total_amount',
                'payment_status',
                'status',
                'created_at',
            ], 'sales_page', $page)
            ->through(fn (Sale $sale) => [
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'total_amount' => (float) $sale->total_amount,
                'payment_status' => $sale->payment_status,
                'status' => $sale->status,
                'created_at' => $sale->created_at?->toIso8601String(),
            ])
            ->withQueryString();
    }

    public function paginatePayments(Customer $customer, ?User $user, int $page = 1, int $perPage = 10): LengthAwarePaginator
    {
        return (clone $this->salesQuery($customer, $user))
            ->where(function (Builder $query) {
                $query->where('payment_status', 'paid')
                    ->orWhere('down_payment_amount', '>', 0);
            })
            ->orderByDesc('created_at')
            ->paginate($perPage, [
                'id',
                'sale_number',
                'total_amount',
                'down_payment_amount',
                'payment_status',
                'payment_method',
                'created_at',
            ], 'payments_page', $page)
            ->through(fn (Sale $sale) => [
                'id' => $sale->id,
                'date' => $sale->created_at?->toIso8601String(),
                'document' => $sale->sale_number,
                'amount' => $this->resolvePaidAmount($sale),
                'payment_method' => $sale->payment_method,
                'reference' => $sale->sale_number,
            ])
            ->withQueryString();
    }

    public function paginateInvoices(Customer $customer, ?User $user, int $page = 1, int $perPage = 10): LengthAwarePaginator
    {
        return (clone $this->salesQuery($customer, $user))
            ->orderByDesc('created_at')
            ->paginate($perPage, [
                'id',
                'sale_number',
                'total_amount',
                'down_payment_amount',
                'remaining_amount',
                'due_date',
                'payment_status',
                'created_at',
            ], 'invoices_page', $page)
            ->through(fn (Sale $sale) => [
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'total_amount' => (float) $sale->total_amount,
                'paid_amount' => $this->resolvePaidAmount($sale),
                'remaining_amount' => (float) $sale->remaining_amount,
                'due_date' => $sale->due_date?->toDateString(),
                'payment_status' => $sale->payment_status,
                'created_at' => $sale->created_at?->toIso8601String(),
            ])
            ->withQueryString();
    }

    public function paginateQuotes(Customer $customer, int $page = 1, int $perPage = 10): LengthAwarePaginator
    {
        return Quote::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->paginate($perPage, [
                'id',
                'quote_number',
                'total_amount',
                'status',
                'created_at',
                'valid_until',
            ], 'quotes_page', $page)
            ->through(fn (Quote $quote) => [
                'id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'total_amount' => (float) $quote->total_amount,
                'status' => $quote->status,
                'created_at' => $quote->created_at?->toIso8601String(),
                'valid_until' => $quote->valid_until?->toDateString(),
            ])
            ->withQueryString();
    }

    public function paginateActivity(Customer $customer, int $page = 1, int $perPage = 10): LengthAwarePaginator
    {
        return ActivityLog::query()
            ->with('user:id,name')
            ->where(function (Builder $query) use ($customer) {
                $query->where(function (Builder $customerQuery) use ($customer) {
                    $customerQuery->where('subject_type', Customer::class)
                        ->where('subject_id', $customer->id);
                })
                    ->orWhere(function (Builder $saleQuery) use ($customer) {
                        $saleQuery->where('subject_type', Sale::class)
                            ->whereIn('subject_id', Sale::query()
                                ->where('customer_id', $customer->id)
                                ->select('id'));
                    })
                    ->orWhere(function (Builder $quoteQuery) use ($customer) {
                        $quoteQuery->where('subject_type', Quote::class)
                            ->whereIn('subject_id', Quote::query()
                                ->where('customer_id', $customer->id)
                                ->select('id'));
                    });
            })
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'activity_page', $page)
            ->through(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'action_label' => $log->action_label,
                'module' => $log->module,
                'description' => $log->description,
                'created_at' => $log->created_at?->toIso8601String(),
                'user_name' => $log->user?->name,
            ])
            ->withQueryString();
    }

    private function resolvePaidAmount(Sale $sale): float
    {
        if ($sale->payment_status === 'paid') {
            return (float) $sale->total_amount;
        }

        return (float) $sale->down_payment_amount;
    }
}
