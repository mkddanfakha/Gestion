<?php

namespace App\Http\Controllers;

use App\Services\Treasury\AuthorizedTreasuryReadService;
use App\Services\Treasury\PaymentMethodNormalizer;
use App\Services\Treasury\TreasuryMovement;
use App\Services\Treasury\TreasuryReadFilter;
use App\Services\Treasury\TreasuryReport;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TreasuryController extends Controller
{
    private const PER_PAGE = 50;

    /** @var array<string, string> */
    private const PAYMENT_METHOD_LABELS = [
        'cash' => 'Espèces',
        'card' => 'Carte bancaire',
        'bank_transfer' => 'Virement',
        'check' => 'Chèque',
        'orange_money' => 'Orange Money',
        'wave' => 'Wave',
        'mobile_money' => 'Mobile money',
        'unknown' => 'Non reconnu',
    ];

    public function __construct(
        private readonly AuthorizedTreasuryReadService $authorizedTreasuryReadService,
    ) {}

    public function index(Request $request): Response
    {
        $this->checkPermission($request, 'treasury', 'view');

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'payment_method' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $from = isset($validated['date_from'])
            ? Carbon::parse($validated['date_from'])->startOfDay()
            : now()->copy()->startOfMonth()->startOfDay();

        $to = isset($validated['date_to'])
            ? Carbon::parse($validated['date_to'])->endOfDay()
            : now()->copy()->endOfDay();

        $paymentMethod = $validated['payment_method'] ?? null;
        if ($paymentMethod === '') {
            $paymentMethod = null;
        }

        $filter = new TreasuryReadFilter(
            from: $from,
            to: $to,
            paymentMethodCanonical: $paymentMethod,
        );

        $report = $this->authorizedTreasuryReadService->reportForUser($request->user(), $filter);

        $page = max(1, (int) ($validated['page'] ?? 1));
        $movementPayloads = array_map(
            fn (TreasuryMovement $movement): array => $this->movementPayload($movement),
            $report->movements,
        );

        $paginator = $this->paginateArray($movementPayloads, $page, self::PER_PAGE, $request);

        return Inertia::render('Treasury/Index', [
            'movements' => $paginator,
            'periodTotals' => [
                'total_inflows' => $report->periodTotals->totalInflows,
                'total_outflows' => $report->periodTotals->totalOutflows,
                'net' => $report->periodTotals->net,
            ],
            'totalsByPaymentMethod' => $this->formatTotalsByMethod($report),
            'paymentMethods' => $this->paymentMethodOptions(),
            'filters' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'payment_method' => $paymentMethod ?? '',
            ],
            'disclaimer' => TreasuryReport::DISCLAIMER,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function paginateArray(array $items, int $page, int $perPage, Request $request): LengthAwarePaginator
    {
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($items, $offset, $perPage);

        return new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function movementPayload(TreasuryMovement $movement): array
    {
        $typeLabel = match ($movement->sourceType) {
            TreasuryMovement::SOURCE_SALE_PAYMENT => $movement->isHistoricalBackfill
                ? 'Reprise historique'
                : 'Encaissement vente',
            TreasuryMovement::SOURCE_EXPENSE => 'Dépense',
            default => $movement->sourceType,
        };

        return [
            'movement_key' => $movement->movementKey,
            'source_type' => $movement->sourceType,
            'source_id' => $movement->sourceId,
            'type_label' => $typeLabel,
            'occurred_at' => $movement->occurredAt->toIso8601String(),
            'label' => $movement->label,
            'payment_method_raw' => $movement->paymentMethodRaw,
            'payment_method_canonical' => $movement->paymentMethodCanonical,
            'payment_method_label' => $this->paymentMethodLabel($movement->paymentMethodCanonical),
            'payment_method_known' => $movement->paymentMethodKnown,
            'amount' => $movement->amount,
            'is_historical_backfill' => $movement->isHistoricalBackfill,
            'sale_id' => $movement->saleId,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function paymentMethodOptions(): array
    {
        $options = [
            ['value' => '', 'label' => 'Tous les moyens'],
        ];

        foreach (PaymentMethodNormalizer::CANONICAL_METHODS as $method) {
            $options[] = [
                'value' => $method,
                'label' => self::PAYMENT_METHOD_LABELS[$method] ?? $method,
            ];
        }

        return $options;
    }

    /**
     * @return list<array{method: string, label: string, inflows: float, outflows: float, net: float, known: bool}>
     */
    private function formatTotalsByMethod(TreasuryReport $report): array
    {
        $rows = [];

        foreach ($report->totalsByPaymentMethod as $method => $row) {
            $rows[] = [
                'method' => $method,
                'label' => $this->paymentMethodLabel($method),
                'inflows' => $row['inflows'],
                'outflows' => $row['outflows'],
                'net' => $row['net'],
                'known' => $row['known'],
            ];
        }

        return $rows;
    }

    private function paymentMethodLabel(string $canonical): string
    {
        return self::PAYMENT_METHOD_LABELS[$canonical] ?? $canonical;
    }
}
