<?php

namespace App\Services;

use App\Enums\InventoryScopeType;
use App\Enums\InventorySessionStatus;
use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventorySession;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InventoryExportService
{
    private const SCOPE_LABELS = [
        'complete' => 'Inventaire complet',
        'category' => 'Catégorie',
        'stock_positive' => 'Stock positif',
    ];

    private const STATUS_LABELS = [
        'draft' => 'Brouillon',
        'counting' => 'Comptage',
        'review' => 'Revue',
        'validated' => 'Validé',
        'applied' => 'Appliqué',
        'closed' => 'Clôturé',
        'cancelled' => 'Annulé',
    ];

    private const VARIANCE_LABELS = [
        'conforme' => 'Conforme',
        'surplus' => 'Surplus',
        'manque' => 'Manque',
        'uncounted' => 'Non compté',
    ];

    public function __construct(
        private readonly InventorySessionService $inventorySessionService,
    ) {}

    public function assertSessionExportable(InventorySession $session): void
    {
        if (! $session->status->isHistory()) {
            throw new HttpException(422, 'L\'export est disponible uniquement pour les inventaires terminés.');
        }
    }

    public function loadSessionForExport(InventorySession $session): InventorySession
    {
        return $session->load([
            'store:id,name',
            'createdBy:id,name',
            'validatedBy:id,name',
            'appliedBy:id,name',
            'closedBy:id,name',
            'cancelledBy:id,name',
            'items.product:id,name,barcode,sku',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPdfData(InventorySession $session): array
    {
        $session = $this->loadSessionForExport($session);
        $summary = $this->inventorySessionService->getSummary($session);
        $progress = $this->inventorySessionService->getProgress($session);
        $detailRows = $this->buildDetailRows($session);
        $varianceRows = $this->buildVarianceRows($session);
        $movementRows = $this->buildMovementRows($this->getSessionMovements($session));

        return [
            'session' => $session,
            'meta' => $this->buildMeta($session),
            'kpi' => $this->buildKpi($session, $summary, $progress),
            'detailRows' => $detailRows,
            'varianceRows' => $varianceRows,
            'movementRows' => $movementRows,
            'generatedAt' => now()->format('d/m/Y H:i'),
        ];
    }

    /**
     * @return Collection<int, StockMovement>
     */
    public function getSessionMovements(InventorySession $session): Collection
    {
        if (! in_array($session->status, [InventorySessionStatus::Applied, InventorySessionStatus::Closed], true)) {
            return collect();
        }

        return StockMovement::query()
            ->where('company_id', $session->company_id)
            ->where('type', StockMovementType::InventoryAdjustment)
            ->where('reference_type', $session->getMorphClass())
            ->where('reference_id', $session->id)
            ->with(['product:id,name,barcode,sku', 'user:id,name'])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSessionMovementsPayload(InventorySession $session): array
    {
        return $this->buildMovementRows($this->getSessionMovements($session));
    }

    /**
     * @return array<string, mixed>
     */
    public function buildMeta(InventorySession $session): array
    {
        return [
            'reference' => $session->reference,
            'name' => $session->name,
            'description' => $session->description,
            'status' => $session->status->value,
            'status_label' => $this->statusLabel($session->status),
            'scope_type' => $session->scope_type->value,
            'scope_label' => $this->scopeLabel($session),
            'category_name' => $this->resolveCategoryName($session),
            'store_name' => $session->store?->name,
            'session_date' => $this->formatDateTime($this->resolveSessionDate($session)),
            'created_by' => $session->createdBy?->name,
            'validated_by' => $session->validatedBy?->name,
            'applied_by' => $session->appliedBy?->name,
            'closed_by' => $session->closedBy?->name,
            'cancelled_by' => $session->cancelledBy?->name,
        ];
    }

    /**
     * @return array<string, int|float|string|null>
     */
    public function buildKpi(InventorySession $session, ?array $summary = null, ?array $progress = null): array
    {
        $summary ??= $this->inventorySessionService->getSummary($session);
        $progress ??= $this->inventorySessionService->getProgress($session);

        return [
            'products' => $progress['total'],
            'counted' => $progress['counted'],
            'conforme' => $summary['zero_variances'],
            'surplus' => $summary['positive_variances'],
            'manquants' => $summary['negative_variances'],
            'net_variance' => $summary['total_variance'],
            'net_variance_label' => $this->formatSignedQuantity($summary['total_variance']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildDetailRows(InventorySession $session): array
    {
        return $session->items
            ->map(function (InventoryItem $item) {
                $difference = $item->quantity_counted !== null
                    ? $item->differenceFromSnapshot()
                    : null;

                return [
                    'product_name' => $item->product->name,
                    'sku' => $item->product->sku,
                    'barcode' => $item->product->barcode,
                    'stock_snapshot' => $item->stock_snapshot,
                    'quantity_counted' => $item->quantity_counted,
                    'difference' => $difference,
                    'difference_label' => $difference === null ? '—' : $this->formatSignedQuantity($difference),
                    'variance_type' => $this->varianceType($item),
                    'variance_label' => $this->varianceLabel($this->varianceType($item)),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildVarianceRows(InventorySession $session): array
    {
        return collect($this->buildDetailRows($session))
            ->filter(fn (array $row) => ($row['difference'] ?? 0) !== 0)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, StockMovement>  $movements
     * @return list<array<string, mixed>>
     */
    public function buildMovementRows(Collection $movements): array
    {
        return $movements
            ->map(function (StockMovement $movement) {
                return [
                    'product_name' => $movement->product?->name ?? '—',
                    'barcode' => $movement->product?->barcode,
                    'type' => $movement->type->value,
                    'type_label' => 'Ajustement inventaire',
                    'quantity' => $movement->quantity,
                    'quantity_label' => $this->formatSignedQuantity($movement->quantity),
                    'created_at' => $this->formatDateTime($movement->created_at),
                    'user_name' => $movement->user?->name ?? '—',
                    'reason' => $movement->reason,
                    'inventory_reference' => $movement->metadata['inventory_reference'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function summarySheetHeadings(): array
    {
        return ['Champ', 'Valeur'];
    }

    /**
     * @return list<list<string|int|null>>
     */
    public function summarySheetRows(InventorySession $session): array
    {
        $session = $this->loadSessionForExport($session);
        $meta = $this->buildMeta($session);
        $kpi = $this->buildKpi($session);

        return [
            ['Référence', $meta['reference']],
            ['Nom', $meta['name']],
            ['Description', $meta['description'] ?? ''],
            ['Statut', $meta['status_label']],
            ['Date', $meta['session_date']],
            ['Utilisateur', $meta['created_by'] ?? ''],
            ['Périmètre', $meta['scope_label']],
            ['Catégorie', $meta['category_name'] ?? ''],
            ['Magasin', $meta['store_name'] ?? ''],
            ['Produits', $kpi['products']],
            ['Comptés', $kpi['counted']],
            ['Conformes', $kpi['conforme']],
            ['Surplus', $kpi['surplus']],
            ['Manquants', $kpi['manquants']],
            ['Écart net', $kpi['net_variance_label']],
        ];
    }

    /**
     * @return list<string>
     */
    public function detailSheetHeadings(): array
    {
        return [
            'Produit',
            'SKU',
            'Code-barres',
            'Stock théorique',
            'Compté',
            'Écart',
            'Type',
        ];
    }

    /**
     * @return list<list<string|int|null>>
     */
    public function detailSheetRows(InventorySession $session): array
    {
        return collect($this->buildDetailRows($session))
            ->map(fn (array $row) => [
                $row['product_name'],
                $row['sku'],
                $row['barcode'],
                $row['stock_snapshot'],
                $row['quantity_counted'],
                $row['difference_label'],
                $row['variance_label'],
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    public function varianceSheetHeadings(): array
    {
        return $this->detailSheetHeadings();
    }

    /**
     * @return list<list<string|int|null>>
     */
    public function varianceSheetRows(InventorySession $session): array
    {
        return collect($this->buildVarianceRows($session))
            ->map(fn (array $row) => [
                $row['product_name'],
                $row['sku'],
                $row['barcode'],
                $row['stock_snapshot'],
                $row['quantity_counted'],
                $row['difference_label'],
                $row['variance_label'],
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    public function movementSheetHeadings(): array
    {
        return [
            'Produit',
            'Type mouvement',
            'Quantité',
            'Date',
            'Utilisateur',
            'Référence inventaire',
        ];
    }

    /**
     * @return list<list<string|int|null>>
     */
    public function movementSheetRows(InventorySession $session): array
    {
        return collect($this->buildMovementRows($this->getSessionMovements($session)))
            ->map(fn (array $row) => [
                $row['product_name'],
                $row['type_label'],
                $row['quantity_label'],
                $row['created_at'],
                $row['user_name'],
                $session->reference,
            ])
            ->all();
    }

    public function scopeLabel(InventorySession $session): string
    {
        $label = self::SCOPE_LABELS[$session->scope_type->value] ?? $session->scope_type->value;

        if ($session->scope_type === InventoryScopeType::Category) {
            $categoryName = $this->resolveCategoryName($session);

            return $categoryName ? "{$label} : {$categoryName}" : $label;
        }

        return $label;
    }

    public function resolveCategoryName(InventorySession $session): ?string
    {
        if ($session->scope_type !== InventoryScopeType::Category) {
            return null;
        }

        $categoryId = $session->scope_value['category_id'] ?? null;

        if (! $categoryId) {
            return null;
        }

        return Category::query()->whereKey($categoryId)->value('name');
    }

    public function statusLabel(InventorySessionStatus $status): string
    {
        return self::STATUS_LABELS[$status->value] ?? $status->value;
    }

    public function varianceLabel(string $varianceType): string
    {
        return self::VARIANCE_LABELS[$varianceType] ?? $varianceType;
    }

    public function resolveSessionDate(InventorySession $session): mixed
    {
        return $session->closed_at
            ?? $session->applied_at
            ?? $session->cancelled_at
            ?? $session->validated_at
            ?? $session->created_at;
    }

    public function formatDateTime(mixed $date): string
    {
        if ($date === null) {
            return '';
        }

        if ($date instanceof \DateTimeInterface) {
            return $date->format('d/m/Y H:i');
        }

        return '';
    }

    public function formatSignedQuantity(int $quantity): string
    {
        if ($quantity > 0) {
            return '+'.$quantity;
        }

        return (string) $quantity;
    }

    private function varianceType(InventoryItem $item): string
    {
        if ($item->quantity_counted === null) {
            return 'uncounted';
        }

        $difference = $item->differenceFromSnapshot();

        if ($difference === 0) {
            return 'conforme';
        }

        return $difference > 0 ? 'surplus' : 'manque';
    }
}
