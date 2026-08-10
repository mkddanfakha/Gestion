<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public const PERIOD_TODAY = 'today';

    public const PERIOD_WEEK = 'week';

    public const PERIOD_MONTH = 'month';

    public const PERIOD_QUARTER = 'quarter';

    public const PERIOD_YEAR = 'year';

    public const PERIOD_CUSTOM = 'custom';

    public function resolvePeriod(Request $request): array
    {
        $period = $request->input('period', self::PERIOD_MONTH);

        if ($period === self::PERIOD_CUSTOM && $request->filled('date_from') && $request->filled('date_to')) {
            $start = Carbon::parse($request->input('date_from'))->startOfDay();
            $end = Carbon::parse($request->input('date_to'))->endOfDay();
        } else {
            [$start, $end] = match ($period) {
                self::PERIOD_TODAY => [now()->startOfDay(), now()->endOfDay()],
                self::PERIOD_WEEK => [now()->startOfWeek(), now()->endOfWeek()],
                self::PERIOD_QUARTER => [now()->startOfQuarter(), now()->endOfQuarter()],
                self::PERIOD_YEAR => [now()->startOfYear(), now()->endOfYear()],
                default => [now()->startOfMonth(), now()->endOfMonth()],
            };
        }

        $days = max(1, $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1);
        $previousEnd = $start->copy()->subDay()->endOfDay();
        $previousStart = $previousEnd->copy()->subDays($days - 1)->startOfDay();

        return [
            'period' => $period,
            'start' => $start,
            'end' => $end,
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
            'label' => $this->periodLabel($period, $start, $end),
        ];
    }

    public function build(User $user, array $period): array
    {
        $canViewFinancials = $user->isAdmin() || $user->hasPermission('expenses', 'view');

        return [
            'filters' => [
                'period' => $period['period'],
                'date_from' => $period['start']->toDateString(),
                'date_to' => $period['end']->toDateString(),
                'label' => $period['label'],
            ],
            'refreshed_at' => now()->format('d/m/Y H:i'),
            'kpis' => $this->kpis($user, $period, $canViewFinancials),
            'sales_chart' => $this->salesChart($user, $period),
            'payment_methods' => $this->paymentMethods($user, $period),
            'top_products' => $this->topProducts($user, $period),
            'stock_alerts' => $this->stockAlerts(),
            'invoice_alerts' => $this->invoiceAlerts($user),
            'recent_sales' => $this->recentSales($user),
            'recent_expenses' => $canViewFinancials ? $this->recentExpenses($user) : [],
            'recent_activity' => $user->isAdmin() ? $this->recentActivity() : [],
            'activity_stats' => $user->isAdmin() ? $this->activityStats() : [
                'actions_today' => 0,
                'logins_today' => 0,
                'deletions_today' => 0,
            ],
            'can_view_financials' => $canViewFinancials,
        ];
    }

    protected function periodLabel(string $period, Carbon $start, Carbon $end): string
    {
        return match ($period) {
            self::PERIOD_TODAY => "Aujourd'hui",
            self::PERIOD_WEEK => 'Cette semaine',
            self::PERIOD_QUARTER => 'Ce trimestre',
            self::PERIOD_YEAR => 'Cette année',
            self::PERIOD_CUSTOM => $start->format('d/m/Y').' – '.$end->format('d/m/Y'),
            default => 'Ce mois',
        };
    }

    protected function salesQuery(User $user): Builder
    {
        return Sale::query()->visibleTo($user);
    }

    protected function revenueForRange(User $user, Carbon $start, Carbon $end): float
    {
        return (float) $this->salesQuery($user)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(CASE WHEN payment_status = "paid" THEN total_amount ELSE down_payment_amount END), 0) as revenue')
            ->value('revenue');
    }

    protected function kpis(User $user, array $period, bool $canViewFinancials): array
    {
        $start = $period['start'];
        $end = $period['end'];
        $prevStart = $period['previous_start'];
        $prevEnd = $period['previous_end'];

        $revenue = $this->revenueForRange($user, $start, $end);
        $previousRevenue = $this->revenueForRange($user, $prevStart, $prevEnd);

        $salesCount = $this->salesQuery($user)->whereBetween('created_at', [$start, $end])->count();
        $previousSalesCount = $this->salesQuery($user)->whereBetween('created_at', [$prevStart, $prevEnd])->count();

        $remainingQuery = $this->salesQuery($user)->where('payment_status', '!=', 'paid');
        $remainingToReceive = (float) (clone $remainingQuery)->sum('remaining_amount');
        $previousRemaining = (float) (clone $remainingQuery)
            ->where('updated_at', '<=', $prevEnd)
            ->sum('remaining_amount');

        $lowStock = Product::query()
            ->where('is_active', true)
            ->whereRaw('stock_quantity <= min_stock_level')
            ->count();

        $items = [];

        if ($user->isAdmin() || $user->hasPermission('sales', 'view')) {
            array_push($items, ...[
                [
                    'key' => 'revenue',
                    'label' => "Chiffre d'affaires",
                    'value' => $revenue,
                    'format' => 'currency',
                    'icon' => 'bi-graph-up-arrow',
                    'tone' => 'primary',
                    'change' => $this->percentChange($revenue, $previousRevenue),
                    'href' => 'sales.index',
                ],
                [
                    'key' => 'sales_count',
                    'label' => 'Ventes',
                    'value' => $salesCount,
                    'format' => 'number',
                    'icon' => 'bi-cart-check',
                    'tone' => 'success',
                    'change' => $this->percentChange($salesCount, $previousSalesCount),
                    'href' => 'sales.index',
                ],
                [
                    'key' => 'remaining',
                    'label' => 'Reste à recevoir',
                    'value' => $remainingToReceive,
                    'format' => 'currency',
                    'icon' => 'bi-wallet2',
                    'tone' => 'warning',
                    'change' => $this->percentChange($remainingToReceive, $previousRemaining),
                    'href' => 'sales.index',
                ],
            ]);
        }

        if ($user->isAdmin() || $user->hasPermission('products', 'view')) {
            $items[] = [
                'key' => 'low_stock',
                'label' => 'Stock faible',
                'value' => $lowStock,
                'format' => 'number',
                'icon' => 'bi-exclamation-triangle',
                'tone' => 'danger',
                'change' => null,
                'href' => 'products.index',
                'href_params' => ['stock_status' => 'low'],
            ];
        }

        if ($canViewFinancials) {
            $expensesAmount = (float) Expense::query()->visibleTo($user)->whereBetween('expense_date', [$start, $end])->sum('amount');
            $previousExpenses = (float) Expense::query()->visibleTo($user)->whereBetween('expense_date', [$prevStart, $prevEnd])->sum('amount');
            $deliveryCosts = (float) DeliveryNote::query()->where('status', 'validated')->whereBetween('created_at', [$start, $end])->sum('total_amount');
            $previousDeliveryCosts = (float) DeliveryNote::query()->where('status', 'validated')->whereBetween('created_at', [$prevStart, $prevEnd])->sum('total_amount');
            $costs = $expensesAmount + $deliveryCosts;
            $previousCosts = $previousExpenses + $previousDeliveryCosts;
            $netProfit = $revenue - $costs;

            $items[] = [
                'key' => 'expenses',
                'label' => 'Dépenses',
                'value' => $expensesAmount,
                'format' => 'currency',
                'icon' => 'bi-receipt',
                'tone' => 'neutral',
                'change' => $this->percentChange($expensesAmount, $previousExpenses),
                'href' => 'expenses.index',
            ];

            $items[] = [
                'key' => 'net_profit',
                'label' => 'Bénéfice net',
                'value' => $netProfit,
                'format' => 'currency',
                'icon' => 'bi-piggy-bank',
                'tone' => $netProfit >= 0 ? 'success' : 'danger',
                'change' => $this->percentChange($revenue - $costs, $previousRevenue - $previousCosts),
                'href' => null,
            ];
        }

        if ($user->isAdmin() || $user->hasPermission('customers', 'view')) {
            $items[] = [
                'key' => 'customers',
                'label' => 'Clients',
                'value' => Customer::count(),
                'format' => 'number',
                'icon' => 'bi-people',
                'tone' => 'info',
                'change' => null,
                'href' => 'customers.index',
            ];
        }

        if ($user->isAdmin() || $user->hasPermission('products', 'view')) {
            $items[] = [
                'key' => 'products',
                'label' => 'Produits actifs',
                'value' => Product::where('is_active', true)->count(),
                'format' => 'number',
                'icon' => 'bi-box-seam',
                'tone' => 'neutral',
                'change' => null,
                'href' => 'products.index',
            ];
        }

        return $items;
    }

    protected function percentChange(float $current, float $previous): ?array
    {
        if ($previous == 0.0) {
            if ($current == 0.0) {
                return null;
            }

            return ['direction' => 'up', 'value' => 100.0];
        }

        $change = (($current - $previous) / $previous) * 100;

        return [
            'direction' => $change >= 0 ? 'up' : 'down',
            'value' => round(abs($change), 1),
        ];
    }

    protected function salesChart(User $user, array $period): array
    {
        $start = $period['start']->copy();
        $end = $period['end']->copy();
        $useMonthlyBuckets = $period['period'] === self::PERIOD_YEAR || $period['period'] === self::PERIOD_QUARTER;

        if ($useMonthlyBuckets) {
            $rows = $this->salesQuery($user)
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as bucket')
                ->selectRaw('COALESCE(SUM(CASE WHEN payment_status = "paid" THEN total_amount ELSE down_payment_amount END), 0) as total')
                ->groupBy('bucket')
                ->orderBy('bucket')
                ->get()
                ->keyBy('bucket');

            $points = [];
            $cursor = $start->copy()->startOfMonth();
            while ($cursor <= $end) {
                $key = $cursor->format('Y-m');
                $points[] = [
                    'label' => $cursor->translatedFormat('M Y'),
                    'bucket' => $key,
                    'total' => (float) ($rows[$key]->total ?? 0),
                ];
                $cursor->addMonth();
            }

            return $points;
        }

        $rows = $this->salesQuery($user)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as bucket')
            ->selectRaw('COALESCE(SUM(CASE WHEN payment_status = "paid" THEN total_amount ELSE down_payment_amount END), 0) as total')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        $points = [];
        $cursor = $start->copy()->startOfDay();
        while ($cursor <= $end) {
            $key = $cursor->toDateString();
            $points[] = [
                'label' => $cursor->translatedFormat('d M'),
                'bucket' => $key,
                'total' => (float) ($rows[$key]->total ?? 0),
            ];
            $cursor->addDay();
        }

        return $points;
    }

    protected function paymentMethods(User $user, array $period): array
    {
        $labels = [
            'cash' => 'Espèces',
            'card' => 'Carte',
            'bank_transfer' => 'Virement',
            'check' => 'Chèque',
            'orange_money' => 'Orange Money',
            'wave' => 'Wave',
        ];

        $rows = $this->salesQuery($user)
            ->whereBetween('created_at', [$period['start'], $period['end']])
            ->whereNotNull('payment_method')
            ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(CASE WHEN payment_status = "paid" THEN total_amount ELSE down_payment_amount END), 0) as amount'))
            ->groupBy('payment_method')
            ->orderByDesc('amount')
            ->get();

        $totalAmount = max(1, (float) $rows->sum('amount'));

        return $rows->map(function ($row) use ($labels, $totalAmount) {
            $amount = (float) $row->amount;

            return [
                'method' => $row->payment_method,
                'label' => $labels[$row->payment_method] ?? ucfirst(str_replace('_', ' ', $row->payment_method)),
                'count' => (int) $row->count,
                'amount' => $amount,
                'percentage' => round(($amount / $totalAmount) * 100, 1),
            ];
        })->values()->all();
    }

    protected function topProducts(User $user, array $period, int $limit = 5): array
    {
        $saleIds = $this->salesQuery($user)
            ->whereBetween('created_at', [$period['start'], $period['end']])
            ->pluck('id');

        if ($saleIds->isEmpty()) {
            return [];
        }

        $rows = SaleItem::query()
            ->whereIn('sale_id', $saleIds)
            ->select('product_id', DB::raw('SUM(quantity) as total_quantity'), DB::raw('COUNT(*) as sales_count'))
            ->groupBy('product_id')
            ->orderByDesc('total_quantity')
            ->limit($limit)
            ->get();

        $products = Product::with('category')
            ->whereIn('id', $rows->pluck('product_id'))
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($products) {
            $product = $products->get($row->product_id);
            if (! $product) {
                return null;
            }

            $firstImage = $product->getFirstMedia('images');

            return [
                'id' => $product->id,
                'name' => $product->name,
                'price' => (float) $product->price,
                'sales_count' => (int) $row->sales_count,
                'total_quantity' => (int) $row->total_quantity,
                'image_url' => $firstImage ? $firstImage->getUrl('thumb') : null,
                'category' => $product->category ? [
                    'name' => $product->category->name,
                    'color' => $product->category->color,
                ] : null,
            ];
        })->filter()->values()->all();
    }

    protected function stockAlerts(): array
    {
        $lowStock = Product::with('category')
            ->where('is_active', true)
            ->whereRaw('stock_quantity <= min_stock_level')
            ->orderBy('stock_quantity')
            ->limit(5)
            ->get()
            ->map(fn (Product $product) => $this->mapStockAlert($product, $product->stock_quantity === 0 ? 'critical' : 'warning'));

        $expiring = Product::with('category')
            ->whereNotNull('expiration_date')
            ->where('is_active', true)
            ->get()
            ->filter(fn (Product $product) => $product->isExpired() || $product->isExpiringSoon())
            ->sortBy('expiration_date')
            ->take(5)
            ->map(fn (Product $product) => $this->mapExpiringAlert($product));

        return [
            'low_stock' => $lowStock->values()->all(),
            'expiring' => $expiring->values()->all(),
            'low_stock_total' => Product::where('is_active', true)->whereRaw('stock_quantity <= min_stock_level')->count(),
            'expiring_total' => Product::whereNotNull('expiration_date')->where('is_active', true)->get()->filter(fn ($p) => $p->isExpired() || $p->isExpiringSoon())->count(),
        ];
    }

    protected function mapStockAlert(Product $product, string $severity): array
    {
        $firstImage = $product->getFirstMedia('images');

        return [
            'id' => $product->id,
            'name' => $product->name,
            'severity' => $severity,
            'type' => 'stock',
            'stock_quantity' => (int) $product->stock_quantity,
            'min_stock_level' => (int) $product->min_stock_level,
            'unit' => $product->unit,
            'image_url' => $firstImage ? $firstImage->getUrl('thumb') : null,
            'category' => $product->category?->name,
            'message' => $product->stock_quantity === 0 ? 'Rupture de stock' : 'Stock faible',
        ];
    }

    protected function mapExpiringAlert(Product $product): array
    {
        $firstImage = $product->getFirstMedia('images');
        $expired = $product->isExpired();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'severity' => $expired ? 'critical' : 'warning',
            'type' => 'expiration',
            'expiration_date' => $product->expiration_date->format('Y-m-d'),
            'days_until_expiration' => $product->days_until_expiration,
            'image_url' => $firstImage ? $firstImage->getUrl('thumb') : null,
            'category' => $product->category?->name,
            'message' => $expired
                ? 'Expiré depuis '.abs((int) $product->days_until_expiration).' jour(s)'
                : ($product->days_until_expiration === 0
                    ? "Expire aujourd'hui"
                    : 'Expire dans '.(int) $product->days_until_expiration.' jour(s)'),
        ];
    }

    protected function invoiceAlerts(User $user): array
    {
        $baseQuery = fn () => $this->salesQuery($user)
            ->with('customer')
            ->whereNotNull('due_date')
            ->where('payment_status', '!=', 'paid');

        $dueToday = $baseQuery()
            ->whereDate('due_date', now()->toDateString())
            ->orderBy('due_date')
            ->limit(5)
            ->get()
            ->map(fn (Sale $sale) => $this->mapInvoiceAlert($sale, 'info'));

        $overdue = $baseQuery()
            ->whereDate('due_date', '<', now()->toDateString())
            ->orderBy('due_date')
            ->limit(5)
            ->get()
            ->map(fn (Sale $sale) => $this->mapInvoiceAlert($sale, 'warning'));

        return [
            'due_today' => $dueToday->values()->all(),
            'overdue' => $overdue->values()->all(),
            'due_today_total' => $baseQuery()->whereDate('due_date', now()->toDateString())->count(),
            'overdue_total' => $baseQuery()->whereDate('due_date', '<', now()->toDateString())->count(),
        ];
    }

    protected function mapInvoiceAlert(Sale $sale, string $severity): array
    {
        $daysOverdue = 0;
        if ($sale->due_date && $sale->due_date->isPast()) {
            $daysOverdue = (int) $sale->due_date->diffInDays(now()->startOfDay());
        }

        return [
            'id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'customer' => $sale->customer?->name ?? 'Client anonyme',
            'total_amount' => (float) $sale->total_amount,
            'remaining_amount' => (float) ($sale->remaining_amount ?? $sale->total_amount),
            'due_date' => $sale->due_date?->format('Y-m-d'),
            'payment_status' => $sale->payment_status,
            'severity' => $severity,
            'days_overdue' => $daysOverdue,
        ];
    }

    protected function recentSales(User $user): array
    {
        return $this->salesQuery($user)
            ->with('customer')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'total_amount' => (float) $sale->total_amount,
                'payment_method' => $sale->payment_method,
                'payment_status' => $sale->payment_status,
                'created_at' => $sale->created_at->format('d/m/Y H:i'),
                'customer' => $sale->customer?->name,
            ])
            ->all();
    }

    protected function recentExpenses(User $user): array
    {
        return Expense::query()
            ->visibleTo($user)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'title' => $expense->title,
                'amount' => (float) $expense->amount,
                'category_label' => $expense->category_label,
                'created_at' => $expense->created_at->format('d/m/Y H:i'),
            ])
            ->all();
    }

    protected function recentActivity(): array
    {
        return ActivityLog::with('user')
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'user_name' => $log->user?->name ?? 'Système',
                'created_at' => $log->created_at->format('d/m/Y H:i'),
            ])
            ->all();
    }

    protected function activityStats(): array
    {
        $today = now()->toDateString();

        return [
            'actions_today' => ActivityLog::whereDate('created_at', $today)->count(),
            'logins_today' => ActivityLog::whereDate('created_at', $today)->where('action', ActivityLog::ACTION_LOGIN)->count(),
            'deletions_today' => ActivityLog::whereDate('created_at', $today)->where('action', ActivityLog::ACTION_DELETE)->count(),
        ];
    }
}
