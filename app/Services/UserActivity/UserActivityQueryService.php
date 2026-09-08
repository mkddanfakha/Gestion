<?php

namespace App\Services\UserActivity;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Journal commercial unifié à partir des tables métier (pas activity_logs).
 *
 * Périmètre : ventes, dépenses, devis, BC, BL, inventaires.
 * Les stock_movements sont exclus (conséquences techniques, pas d'activité commerciale).
 */
class UserActivityQueryService
{
    public const TYPE_SALE = 'sale';

    public const TYPE_EXPENSE = 'expense';

    public const TYPE_QUOTE = 'quote';

    public const TYPE_PURCHASE_ORDER = 'purchase_order';

    public const TYPE_DELIVERY_NOTE = 'delivery_note';

    public const TYPE_INVENTORY = 'inventory';

    /**
     * @return array<string, string>
     */
    public static function activityTypes(): array
    {
        return [
            self::TYPE_SALE => 'Ventes',
            self::TYPE_EXPENSE => 'Dépenses',
            self::TYPE_QUOTE => 'Devis',
            self::TYPE_PURCHASE_ORDER => 'Bons de commande',
            self::TYPE_DELIVERY_NOTE => 'Bons de livraison',
            self::TYPE_INVENTORY => 'Inventaires',
        ];
    }

    /**
     * @param  array{
     *     user_id?: mixed,
     *     activity_type?: mixed,
     *     period?: mixed,
     *     date_from?: mixed,
     *     date_to?: mixed,
     *     search?: mixed,
     *     page?: mixed,
     * }  $filters
     * @return array{
     *     activities: LengthAwarePaginator,
     *     stats: array<string, int|float|string>,
     *     user_summary: list<array<string, mixed>>,
     *     filters: array<string, mixed>,
     *     activity_types: array<string, string>,
     *     period_presets: array<string, string>,
     * }
     */
    public function build(array $filters): array
    {
        $normalized = $this->normalizeFilters($filters);
        $union = $this->unionQuery($normalized);
        $base = DB::query()->fromSub($union, 'ua');

        $activities = (clone $base)
            ->orderByDesc('occurred_at')
            ->orderByDesc('subject_id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($row) => $this->mapRow($row));

        return [
            'activities' => $activities,
            'stats' => $this->stats($normalized),
            'user_summary' => $this->userSummary($normalized),
            'filters' => $normalized,
            'activity_types' => self::activityTypes(),
            'period_presets' => [
                'today' => "Aujourd'hui",
                'yesterday' => 'Hier',
                'this_week' => 'Cette semaine',
                'this_month' => 'Ce mois',
                'last_month' => 'Mois précédent',
                'custom' => 'Personnalisé',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     user_id: int|null,
     *     activity_type: string|null,
     *     period: string,
     *     date_from: string|null,
     *     date_to: string|null,
     *     search: string|null,
     * }
     */
    public function normalizeFilters(array $filters): array
    {
        $period = is_string($filters['period'] ?? null) && $filters['period'] !== ''
            ? $filters['period']
            : 'this_month';

        $allowedPeriods = ['today', 'yesterday', 'this_week', 'this_month', 'last_month', 'custom'];
        if (! in_array($period, $allowedPeriods, true)) {
            $period = 'this_month';
        }

        $rawFrom = is_string($filters['date_from'] ?? null) ? $filters['date_from'] : null;
        $rawTo = is_string($filters['date_to'] ?? null) ? $filters['date_to'] : null;

        // custom sans aucune borne valide → this_month (évite un scan historique complet).
        if ($period === 'custom') {
            $customFrom = $this->validDate($rawFrom);
            $customTo = $this->validDate($rawTo);
            if ($customFrom === null && $customTo === null) {
                $period = 'this_month';
            }
        }

        [$from, $to] = $this->resolvePeriodDates($period, $rawFrom, $rawTo);

        $type = is_string($filters['activity_type'] ?? null) ? $filters['activity_type'] : null;
        if ($type !== null && $type !== '' && ! array_key_exists($type, self::activityTypes())) {
            $type = null;
        }
        if ($type === '') {
            $type = null;
        }

        $userId = $filters['user_id'] ?? null;
        $userId = is_numeric($userId) && (int) $userId > 0 ? (int) $userId : null;

        $search = is_string($filters['search'] ?? null) ? trim($filters['search']) : null;
        if ($search === '') {
            $search = null;
        }

        return [
            'user_id' => $userId,
            'activity_type' => $type,
            'period' => $period,
            'date_from' => $from,
            'date_to' => $to,
            'search' => $search,
        ];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function resolvePeriodDates(string $period, ?string $dateFrom, ?string $dateTo): array
    {
        $today = Carbon::today();

        return match ($period) {
            'today' => [$today->toDateString(), $today->toDateString()],
            'yesterday' => [
                $today->copy()->subDay()->toDateString(),
                $today->copy()->subDay()->toDateString(),
            ],
            'this_week' => [
                $today->copy()->startOfWeek()->toDateString(),
                $today->toDateString(),
            ],
            'this_month' => [
                $today->copy()->startOfMonth()->toDateString(),
                $today->toDateString(),
            ],
            'last_month' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            default => [
                $this->validDate($dateFrom),
                $this->validDate($dateTo),
            ],
        };
    }

    private function validDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Construit l'UNION avec filtres date / user / search poussés dans chaque branche.
     *
     * @param  array{
     *     user_id: int|null,
     *     activity_type: string|null,
     *     period: string,
     *     date_from: string|null,
     *     date_to: string|null,
     *     search: string|null,
     * }  $filters
     */
    private function unionQuery(array $filters): Builder
    {
        $activityType = $filters['activity_type'];
        $parts = [];

        $include = static function (string $type) use ($activityType): bool {
            return $activityType === null || $activityType === $type;
        };

        if ($include(self::TYPE_SALE)) {
            $query = DB::table('sales')
                ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
                ->leftJoin('users', 'users.id', '=', 'sales.user_id')
                ->selectRaw("
                    sales.id as subject_id,
                    'sale' as activity_type,
                    sales.user_id as user_id,
                    users.name as user_name,
                    sales.sale_number as reference,
                    customers.name as party_name,
                    sales.total_amount as amount,
                    sales.status as status,
                    COALESCE(sales.sale_date, sales.created_at) as occurred_at
                ");
            $this->applyBranchFilters(
                $query,
                $filters,
                'COALESCE(sales.sale_date, sales.created_at)',
                'sales.user_id',
                ['sales.sale_number', 'customers.name', 'users.name'],
            );
            $parts[] = $query;
        }

        if ($include(self::TYPE_EXPENSE)) {
            $query = DB::table('expenses')
                ->leftJoin('suppliers', 'suppliers.id', '=', 'expenses.supplier_id')
                ->leftJoin('users', 'users.id', '=', 'expenses.user_id')
                ->selectRaw("
                    expenses.id as subject_id,
                    'expense' as activity_type,
                    expenses.user_id as user_id,
                    users.name as user_name,
                    expenses.expense_number as reference,
                    COALESCE(suppliers.name, expenses.vendor, expenses.category) as party_name,
                    expenses.amount as amount,
                    expenses.category as status,
                    COALESCE(expenses.expense_date, expenses.created_at) as occurred_at
                ");
            $this->applyBranchFilters(
                $query,
                $filters,
                'COALESCE(expenses.expense_date, expenses.created_at)',
                'expenses.user_id',
                ['expenses.expense_number', 'suppliers.name', 'expenses.vendor', 'users.name'],
            );
            $parts[] = $query;
        }

        if ($include(self::TYPE_QUOTE)) {
            $query = DB::table('quotes')
                ->leftJoin('customers', 'customers.id', '=', 'quotes.customer_id')
                ->leftJoin('users', 'users.id', '=', 'quotes.user_id')
                ->selectRaw("
                    quotes.id as subject_id,
                    'quote' as activity_type,
                    quotes.user_id as user_id,
                    users.name as user_name,
                    quotes.quote_number as reference,
                    customers.name as party_name,
                    quotes.total_amount as amount,
                    quotes.status as status,
                    COALESCE(quotes.quote_date, quotes.created_at) as occurred_at
                ");
            $this->applyBranchFilters(
                $query,
                $filters,
                'COALESCE(quotes.quote_date, quotes.created_at)',
                'quotes.user_id',
                ['quotes.quote_number', 'customers.name', 'users.name'],
            );
            $parts[] = $query;
        }

        if ($include(self::TYPE_PURCHASE_ORDER)) {
            $query = DB::table('purchase_orders')
                ->leftJoin('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
                ->leftJoin('users', 'users.id', '=', 'purchase_orders.user_id')
                ->selectRaw("
                    purchase_orders.id as subject_id,
                    'purchase_order' as activity_type,
                    purchase_orders.user_id as user_id,
                    users.name as user_name,
                    purchase_orders.po_number as reference,
                    suppliers.name as party_name,
                    purchase_orders.total_amount as amount,
                    purchase_orders.status as status,
                    COALESCE(purchase_orders.order_date, purchase_orders.created_at) as occurred_at
                ");
            $this->applyBranchFilters(
                $query,
                $filters,
                'COALESCE(purchase_orders.order_date, purchase_orders.created_at)',
                'purchase_orders.user_id',
                ['purchase_orders.po_number', 'suppliers.name', 'users.name'],
            );
            $parts[] = $query;
        }

        if ($include(self::TYPE_DELIVERY_NOTE)) {
            $query = DB::table('delivery_notes')
                ->leftJoin('suppliers', 'suppliers.id', '=', 'delivery_notes.supplier_id')
                ->leftJoin('users', 'users.id', '=', 'delivery_notes.user_id')
                ->selectRaw("
                    delivery_notes.id as subject_id,
                    'delivery_note' as activity_type,
                    delivery_notes.user_id as user_id,
                    users.name as user_name,
                    delivery_notes.delivery_number as reference,
                    suppliers.name as party_name,
                    delivery_notes.total_amount as amount,
                    delivery_notes.status as status,
                    COALESCE(delivery_notes.delivery_date, delivery_notes.created_at) as occurred_at
                ");
            $this->applyBranchFilters(
                $query,
                $filters,
                'COALESCE(delivery_notes.delivery_date, delivery_notes.created_at)',
                'delivery_notes.user_id',
                ['delivery_notes.delivery_number', 'suppliers.name', 'users.name'],
            );
            $parts[] = $query;
        }

        if ($include(self::TYPE_INVENTORY)) {
            $query = DB::table('inventory_sessions')
                ->leftJoin('users', 'users.id', '=', 'inventory_sessions.created_by')
                ->selectRaw("
                    inventory_sessions.id as subject_id,
                    'inventory' as activity_type,
                    inventory_sessions.created_by as user_id,
                    users.name as user_name,
                    inventory_sessions.reference as reference,
                    inventory_sessions.name as party_name,
                    NULL as amount,
                    inventory_sessions.status as status,
                    inventory_sessions.created_at as occurred_at
                ");
            $this->applyBranchFilters(
                $query,
                $filters,
                'inventory_sessions.created_at',
                'inventory_sessions.created_by',
                ['inventory_sessions.reference', 'inventory_sessions.name', 'users.name'],
            );
            $parts[] = $query;
        }

        if ($parts === []) {
            return DB::table('sales')->selectRaw("
                sales.id as subject_id,
                'sale' as activity_type,
                sales.user_id as user_id,
                NULL as user_name,
                sales.sale_number as reference,
                NULL as party_name,
                sales.total_amount as amount,
                sales.status as status,
                sales.created_at as occurred_at
            ")->whereRaw('1 = 0');
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        return $union;
    }

    /**
     * @param  array{
     *     user_id: int|null,
     *     activity_type: string|null,
     *     period: string,
     *     date_from: string|null,
     *     date_to: string|null,
     *     search: string|null,
     * }  $filters
     * @param  list<string>  $searchColumns
     */
    private function applyBranchFilters(
        Builder $query,
        array $filters,
        string $occurredAtSql,
        string $userColumn,
        array $searchColumns,
    ): void {
        if ($filters['user_id'] !== null) {
            $query->where($userColumn, $filters['user_id']);
        }

        if ($filters['date_from'] !== null) {
            $query->whereRaw("DATE({$occurredAtSql}) >= ?", [$filters['date_from']]);
        }

        if ($filters['date_to'] !== null) {
            $query->whereRaw("DATE({$occurredAtSql}) <= ?", [$filters['date_to']]);
        }

        if ($filters['search'] !== null && $searchColumns !== []) {
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search, $searchColumns): void {
                foreach ($searchColumns as $index => $column) {
                    if ($index === 0) {
                        $q->where($column, 'like', "%{$search}%");
                    } else {
                        $q->orWhere($column, 'like', "%{$search}%");
                    }
                }
            });
        }
    }

    /**
     * @param  object{
     *     subject_id: int|string,
     *     activity_type: string,
     *     user_id: int|null,
     *     user_name: string|null,
     *     reference: string|null,
     *     party_name: string|null,
     *     amount: mixed,
     *     status: string|null,
     *     occurred_at: mixed
     * }  $row
     * @return array<string, mixed>
     */
    private function mapRow(object $row): array
    {
        $occurred = $row->occurred_at
            ? Carbon::parse($row->occurred_at)
            : null;

        return [
            'id' => $row->activity_type.':'.$row->subject_id,
            'subject_id' => (int) $row->subject_id,
            'activity_type' => $row->activity_type,
            'activity_label' => self::activityTypes()[$row->activity_type] ?? $row->activity_type,
            'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
            'user_name' => $row->user_name ?: 'Non attribué',
            'reference' => $row->reference,
            'party_name' => $row->party_name,
            'amount' => $row->amount !== null ? (float) $row->amount : null,
            'status' => $row->status,
            'date' => $occurred?->format('d/m/Y'),
            'time' => $occurred?->format('H:i'),
            'occurred_at' => $occurred?->toIso8601String(),
            'show_route' => $this->showRoute($row->activity_type, (int) $row->subject_id),
        ];
    }

    /**
     * @return array{name: string, params: array<string, int>}|null
     */
    private function showRoute(string $type, int $id): ?array
    {
        // Clés alignées sur resources/js/lib/routes.ts ({id} / {session}),
        // comme le reste du frontend (Quotes/Index, Sales/Index, etc.).
        return match ($type) {
            self::TYPE_SALE => ['name' => 'sales.show', 'params' => ['id' => $id]],
            self::TYPE_EXPENSE => ['name' => 'expenses.show', 'params' => ['id' => $id]],
            self::TYPE_QUOTE => ['name' => 'quotes.show', 'params' => ['id' => $id]],
            self::TYPE_PURCHASE_ORDER => ['name' => 'purchase-orders.show', 'params' => ['id' => $id]],
            self::TYPE_DELIVERY_NOTE => ['name' => 'delivery-notes.show', 'params' => ['id' => $id]],
            self::TYPE_INVENTORY => ['name' => 'inventory.show', 'params' => ['session' => $id]],
            default => null,
        };
    }

    /**
     * @param  array{
     *     user_id: int|null,
     *     activity_type: string|null,
     *     period: string,
     *     date_from: string|null,
     *     date_to: string|null,
     *     search: string|null,
     * }  $filters
     * @return array<string, int|float>
     */
    private function stats(array $filters): array
    {
        $union = $this->unionQuery($filters);
        $base = DB::query()->fromSub($union, 'ua');

        $rows = $base
            ->selectRaw('activity_type, COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('activity_type')
            ->get()
            ->keyBy('activity_type');

        $count = static fn (string $type): int => (int) ($rows[$type]->cnt ?? 0);
        $sum = static fn (string $type): float => (float) ($rows[$type]->total_amount ?? 0);

        return [
            'total_activities' => (int) $rows->sum(fn ($r) => (int) $r->cnt),
            'sales_count' => $count(self::TYPE_SALE),
            'sales_amount' => $sum(self::TYPE_SALE),
            'expenses_count' => $count(self::TYPE_EXPENSE),
            'expenses_amount' => $sum(self::TYPE_EXPENSE),
            'quotes_count' => $count(self::TYPE_QUOTE),
            'purchase_orders_count' => $count(self::TYPE_PURCHASE_ORDER),
            'delivery_notes_count' => $count(self::TYPE_DELIVERY_NOTE),
            'inventory_count' => $count(self::TYPE_INVENTORY),
        ];
    }

    /**
     * @param  array{
     *     user_id: int|null,
     *     activity_type: string|null,
     *     period: string,
     *     date_from: string|null,
     *     date_to: string|null,
     *     search: string|null,
     * }  $filters
     * @return list<array<string, mixed>>
     */
    private function userSummary(array $filters): array
    {
        $union = $this->unionQuery($filters);
        $base = DB::query()->fromSub($union, 'ua');

        $rows = $base
            ->selectRaw("
                user_id,
                MAX(user_name) as user_name,
                SUM(CASE WHEN activity_type = 'sale' THEN 1 ELSE 0 END) as sales_count,
                SUM(CASE WHEN activity_type = 'sale' THEN COALESCE(amount, 0) ELSE 0 END) as sales_amount,
                SUM(CASE WHEN activity_type = 'expense' THEN 1 ELSE 0 END) as expenses_count,
                SUM(CASE WHEN activity_type = 'expense' THEN COALESCE(amount, 0) ELSE 0 END) as expenses_amount,
                SUM(CASE WHEN activity_type = 'quote' THEN 1 ELSE 0 END) as quotes_count,
                SUM(CASE WHEN activity_type = 'purchase_order' THEN 1 ELSE 0 END) as purchase_orders_count,
                SUM(CASE WHEN activity_type = 'delivery_note' THEN 1 ELSE 0 END) as delivery_notes_count,
                SUM(CASE WHEN activity_type = 'inventory' THEN 1 ELSE 0 END) as inventory_count,
                COUNT(*) as total_count
            ")
            ->groupBy('user_id')
            ->orderByDesc('sales_amount')
            ->orderBy('user_name')
            ->get();

        return $rows->map(function ($row): array {
            return [
                'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                'user_name' => $row->user_name ?: 'Non attribué',
                'sales_count' => (int) $row->sales_count,
                'sales_amount' => (float) $row->sales_amount,
                'expenses_count' => (int) $row->expenses_count,
                'expenses_amount' => (float) $row->expenses_amount,
                'quotes_count' => (int) $row->quotes_count,
                'purchase_orders_count' => (int) $row->purchase_orders_count,
                'delivery_notes_count' => (int) $row->delivery_notes_count,
                'inventory_count' => (int) $row->inventory_count,
                'total_count' => (int) $row->total_count,
            ];
        })->values()->all();
    }
}
