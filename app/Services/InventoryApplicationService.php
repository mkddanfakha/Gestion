<?php

namespace App\Services;

use App\Enums\InventorySessionStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventorySession;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryApplicationService
{
    private const MODULE = 'Inventaire';

    public function __construct(
        private readonly StockService $stockService,
        private readonly StockLockOrdering $stockLockOrdering,
    ) {}

    public function canApply(InventorySession $session): bool
    {
        return $session->status === InventorySessionStatus::Validated
            && $session->items()->whereNull('quantity_counted')->doesntExist();
    }

    /**
     * Prévisualisation basée sur le stock courant (sans verrouillage).
     *
     * @return array<string, int>
     */
    public function previewApplication(InventorySession $session): array
    {
        $items = $session->items()
            ->orderBy('product_id')
            ->get(['product_id', 'quantity_counted', 'stock_snapshot']);

        $summary = $this->emptyApplicationSummary($items->count());

        foreach ($items as $item) {
            if ($item->quantity_counted === null) {
                continue;
            }

            $currentStock = ProductStock::query()
                ->where('product_id', $item->product_id)
                ->where('store_id', $session->store_id)
                ->value('quantity');

            if ($currentStock === null) {
                continue;
            }

            $delta = $item->quantity_counted - (int) $currentStock;

            if ($delta === 0) {
                $summary['unchanged_items']++;
            } else {
                $summary['adjusted_items']++;

                if ($delta > 0) {
                    $summary['positive_adjustments']++;
                    $summary['total_positive_quantity'] += $delta;
                } else {
                    $summary['negative_adjustments']++;
                    $summary['total_negative_quantity'] += abs($delta);
                }
            }
        }

        $summary['net_adjustment'] = $summary['total_positive_quantity'] - $summary['total_negative_quantity'];

        return $summary;
    }

    /**
     * @return array{session: InventorySession, summary: array<string, int>}
     */
    public function apply(InventorySession $session, User $user): array
    {
        return DB::transaction(function () use ($session, $user): array {
            $lockedSession = InventorySession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertSessionBelongsToCurrentCompany($lockedSession);
            $this->assertSessionApplicable($lockedSession);

            $store = Store::query()->whereKey($lockedSession->store_id)->firstOrFail();

            $uncountedExists = InventoryItem::query()
                ->where('inventory_session_id', $lockedSession->id)
                ->whereNull('quantity_counted')
                ->exists();

            if ($uncountedExists) {
                throw ValidationException::withMessages([
                    'message' => ['Impossible d\'appliquer : des produits ne sont pas comptés.'],
                ]);
            }

            $items = InventoryItem::query()
                ->where('inventory_session_id', $lockedSession->id)
                ->with('product:id,name')
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get();

            $summary = $this->emptyApplicationSummary($items->count());

            try {
                $lockedStock = $this->stockLockOrdering->lockManyProductsAndStocks(
                    $items->pluck('product_id')->all(),
                    $lockedSession->store_id,
                );
            } catch (ModelNotFoundException) {
                throw ValidationException::withMessages([
                    'message' => ['Impossible d\'appliquer : stock produit introuvable pour ce magasin.'],
                ]);
            }

            foreach ($items as $item) {
                if ($item->quantity_counted === null) {
                    throw ValidationException::withMessages([
                        'message' => ['Impossible d\'appliquer : des produits ne sont pas comptés.'],
                    ]);
                }

                $product = $lockedStock['products']->get($item->product_id);
                $productStock = $lockedStock['productStocks']->get($item->product_id);

                if (! $product || ! $productStock) {
                    throw ValidationException::withMessages([
                        'message' => [
                            sprintf(
                                'Le stock du produit %s est introuvable pour ce magasin.',
                                $item->product->name,
                            ),
                        ],
                    ]);
                }

                $stockBeforeApply = (int) $productStock->quantity;
                $delta = $item->quantity_counted - $stockBeforeApply;

                if ($delta === 0) {
                    $summary['unchanged_items']++;

                    continue;
                }

                $metadata = [
                    'inventory_session_id' => $lockedSession->id,
                    'inventory_reference' => $lockedSession->reference,
                    'inventory_item_id' => $item->id,
                    'stock_snapshot' => $item->stock_snapshot,
                    'quantity_counted' => $item->quantity_counted,
                    'stock_before_apply' => $stockBeforeApply,
                    'stock_after_apply' => $item->quantity_counted,
                    'delta_from_current' => $delta,
                    'variance_from_snapshot' => $item->quantity_counted - $item->stock_snapshot,
                    'source' => 'inventory_application',
                ];

                $reason = sprintf(
                    'Inventaire %s (%s)',
                    $lockedSession->reference,
                    $delta > 0 ? "+{$delta}" : (string) $delta,
                );

                try {
                    if ($delta > 0) {
                        $this->stockService->increase(
                            $item->product_id,
                            $store,
                            $delta,
                            StockMovementType::InventoryAdjustment,
                            user: $user,
                            reason: $reason,
                            reference: $lockedSession,
                            metadata: $metadata,
                        );
                        $summary['positive_adjustments']++;
                        $summary['total_positive_quantity'] += $delta;
                    } else {
                        $this->stockService->decrease(
                            $item->product_id,
                            $store,
                            abs($delta),
                            StockMovementType::InventoryAdjustment,
                            user: $user,
                            reason: $reason,
                            reference: $lockedSession,
                            metadata: $metadata,
                        );
                        $summary['negative_adjustments']++;
                        $summary['total_negative_quantity'] += abs($delta);
                    }
                } catch (InsufficientStockException) {
                    throw ValidationException::withMessages([
                        'message' => [
                            sprintf(
                                'Stock insuffisant pour appliquer l\'inventaire sur le produit %s.',
                                $item->product->name,
                            ),
                        ],
                    ]);
                }

                $summary['adjusted_items']++;
                $this->syncProductMirror($product, $store);
            }

            $summary['net_adjustment'] = $summary['total_positive_quantity'] - $summary['total_negative_quantity'];

            $previousStatus = $lockedSession->status;
            $lockedSession->update([
                'status' => InventorySessionStatus::Applied,
                'applied_at' => now(),
                'applied_by' => $user->id,
                'application_summary' => $summary,
            ]);

            ActivityLogger::log(
                ActivityLog::ACTION_UPDATE,
                self::MODULE,
                sprintf('a appliqué l\'inventaire "%s"', $lockedSession->reference),
                $lockedSession->fresh(),
                $user,
                oldValues: ['status' => $previousStatus->value],
                newValues: array_merge(
                    ['status' => InventorySessionStatus::Applied->value],
                    $summary,
                ),
            );

            return [
                'session' => $lockedSession->fresh(['items', 'appliedBy', 'store']),
                'summary' => $summary,
            ];
        });
    }

    /**
     * @return array<string, int>
     */
    private function emptyApplicationSummary(int $totalItems): array
    {
        return [
            'total_items' => $totalItems,
            'adjusted_items' => 0,
            'unchanged_items' => 0,
            'positive_adjustments' => 0,
            'negative_adjustments' => 0,
            'total_positive_quantity' => 0,
            'total_negative_quantity' => 0,
            'net_adjustment' => 0,
        ];
    }

    private function assertSessionApplicable(InventorySession $session): void
    {
        if ($session->status === InventorySessionStatus::Applied) {
            throw ValidationException::withMessages([
                'message' => ['Cet inventaire a déjà été appliqué.'],
            ]);
        }

        if ($session->status !== InventorySessionStatus::Validated) {
            throw ValidationException::withMessages([
                'message' => [
                    sprintf(
                        'Impossible d\'appliquer : la session n\'est pas au statut « %s ».',
                        InventorySessionStatus::Validated->value,
                    ),
                ],
            ]);
        }
    }

    private function assertSessionBelongsToCurrentCompany(InventorySession $session): void
    {
        $company = Company::getInstance();

        if ($session->company_id !== $company->id) {
            throw ValidationException::withMessages([
                'message' => ['Cette session d\'inventaire n\'appartient pas à l\'entreprise courante.'],
            ]);
        }

        $store = $session->relationLoaded('store')
            ? $session->store
            : Store::query()->find($session->store_id);

        if (! $store || $store->company_id !== $company->id) {
            throw ValidationException::withMessages([
                'message' => ['Le magasin de cette session n\'appartient pas à l\'entreprise courante.'],
            ]);
        }
    }

    private function syncProductMirror(Product $product, Store $store): void
    {
        $mirroredQuantity = $this->stockService->getStock($product, $store);
        $product->update(['stock_quantity' => $mirroredQuantity]);
    }
}
