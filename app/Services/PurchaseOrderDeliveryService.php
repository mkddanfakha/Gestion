<?php

namespace App\Services;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderDeliveryService
{
    /** @var list<string> */
    public const DELIVERED_STATUSES = ['validated'];

    /** @var list<string> */
    public const RESERVED_STATUSES = ['pending'];

    /** @var list<string> */
    public const BLOCKED_PO_STATUSES = ['cancelled'];

    /**
     * @return array<string, int>
     */
    public function getDeliveredQuantitiesByProduct(PurchaseOrder $purchaseOrder, ?int $excludeDeliveryNoteId = null): array
    {
        return $this->aggregateItemQuantities($purchaseOrder, self::DELIVERED_STATUSES, $excludeDeliveryNoteId);
    }

    /**
     * @return array<string, int>
     */
    public function getPendingQuantitiesByProduct(PurchaseOrder $purchaseOrder, ?int $excludeDeliveryNoteId = null): array
    {
        return $this->aggregateItemQuantities($purchaseOrder, self::RESERVED_STATUSES, $excludeDeliveryNoteId);
    }

    /**
     * @return array<string, int>
     */
    public function getAvailableQuantitiesByProduct(PurchaseOrder $purchaseOrder, ?int $excludeDeliveryNoteId = null): array
    {
        $purchaseOrder->loadMissing('items');

        $ordered = $purchaseOrder->items->pluck('quantity', 'product_id')->map(fn ($qty) => (int) $qty)->all();
        $delivered = $this->getDeliveredQuantitiesByProduct($purchaseOrder, $excludeDeliveryNoteId);
        $pending = $this->getPendingQuantitiesByProduct($purchaseOrder, $excludeDeliveryNoteId);

        $available = [];

        foreach ($ordered as $productId => $orderedQty) {
            $deliveredQty = $delivered[$productId] ?? 0;
            $pendingQty = $pending[$productId] ?? 0;
            $available[$productId] = max(0, $orderedQty - $deliveredQty - $pendingQty);
        }

        return $available;
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     totals: array<string, int|float>,
     *     progress_percent: float,
     *     can_create_delivery: bool,
     *     is_fully_delivered: bool
     * }
     */
    public function buildReceiptSummary(PurchaseOrder $purchaseOrder, ?int $excludeDeliveryNoteId = null): array
    {
        $purchaseOrder->loadMissing(['items.product']);

        $delivered = $this->getDeliveredQuantitiesByProduct($purchaseOrder, $excludeDeliveryNoteId);
        $pending = $this->getPendingQuantitiesByProduct($purchaseOrder, $excludeDeliveryNoteId);

        $items = [];
        $totalOrdered = 0;
        $totalDelivered = 0;
        $totalPending = 0;

        foreach ($purchaseOrder->items as $item) {
            $orderedQty = (int) $item->quantity;
            $deliveredQty = $delivered[$item->product_id] ?? 0;
            $pendingQty = $pending[$item->product_id] ?? 0;
            $remainingQty = max(0, $orderedQty - $deliveredQty);
            $availableQty = max(0, $orderedQty - $deliveredQty - $pendingQty);

            $totalOrdered += $orderedQty;
            $totalDelivered += $deliveredQty;
            $totalPending += $pendingQty;

            $items[] = [
                'purchase_order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'ordered_quantity' => $orderedQty,
                'delivered_quantity' => $deliveredQty,
                'pending_quantity' => $pendingQty,
                'remaining_quantity' => $remainingQty,
                'available_quantity' => $availableQty,
                'unit_price' => (float) $item->unit_price,
            ];
        }

        $totalRemaining = max(0, $totalOrdered - $totalDelivered);
        $progressPercent = $totalOrdered > 0
            ? round(($totalDelivered / $totalOrdered) * 100, 1)
            : 0.0;

        $canCreateDelivery = ! in_array($purchaseOrder->status, self::BLOCKED_PO_STATUSES, true)
            && $purchaseOrder->status !== 'received'
            && $totalRemaining > 0;

        return [
            'items' => $items,
            'totals' => [
                'ordered' => $totalOrdered,
                'delivered' => $totalDelivered,
                'pending' => $totalPending,
                'remaining' => $totalRemaining,
            ],
            'progress_percent' => $progressPercent,
            'can_create_delivery' => $canCreateDelivery,
            'is_fully_delivered' => $totalOrdered > 0 && $totalRemaining <= 0,
        ];
    }

    public function canCreateDelivery(PurchaseOrder $purchaseOrder): bool
    {
        if (in_array($purchaseOrder->status, self::BLOCKED_PO_STATUSES, true)) {
            return false;
        }

        if ($purchaseOrder->status === 'received') {
            return false;
        }

        $purchaseOrder->loadMissing('items');

        $delivered = $this->getDeliveredQuantitiesByProduct($purchaseOrder);
        $totalOrdered = (int) $purchaseOrder->items->sum('quantity');
        $totalDelivered = 0;

        foreach ($purchaseOrder->items as $item) {
            $totalDelivered += $delivered[$item->product_id] ?? 0;
        }

        return max(0, $totalOrdered - $totalDelivered) > 0;
    }

    /**
     * @param  list<array{product_id:int, quantity:int}>  $items
     *
     * @throws ValidationException
     */
    public function assertDeliveryQuantities(PurchaseOrder $purchaseOrder, array $items, ?DeliveryNote $deliveryNote = null): void
    {
        $purchaseOrder->loadMissing('items');

        $orderedProductIds = $purchaseOrder->items->pluck('product_id')->all();
        $orderedQuantities = $purchaseOrder->items->pluck('quantity', 'product_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();

        $excludeId = $deliveryNote?->id;
        $delivered = $this->getDeliveredQuantitiesByProduct($purchaseOrder, $excludeId);
        $pending = $this->getPendingQuantitiesByProduct($purchaseOrder, $excludeId);

        $requestedByProduct = [];

        foreach ($items as $index => $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($productId <= 0) {
                continue;
            }

            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => ['La quantité à livrer doit être supérieure à 0.'],
                ]);
            }

            if (! in_array($productId, $orderedProductIds, true)) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => ['Ce produit ne fait pas partie du bon de commande.'],
                ]);
            }

            $requestedByProduct[$productId] = ($requestedByProduct[$productId] ?? 0) + $quantity;
        }

        foreach ($requestedByProduct as $productId => $requestedQty) {
            $orderedQty = $orderedQuantities[$productId] ?? 0;
            $deliveredQty = $delivered[$productId] ?? 0;
            $pendingQty = $pending[$productId] ?? 0;
            $availableQty = max(0, $orderedQty - $deliveredQty - $pendingQty);

            if ($requestedQty > $availableQty) {
                $productName = $purchaseOrder->items->firstWhere('product_id', $productId)?->product?->name ?? 'Produit';

                throw ValidationException::withMessages([
                    'items' => [
                        sprintf(
                            'Impossible de livrer %d unité(s) de « %s » : seulement %d unité(s) restent à livrer.',
                            $requestedQty,
                            $productName,
                            $availableQty,
                        ),
                    ],
                ]);
            }
        }
    }

    public function validateDeliveryNote(DeliveryNote $deliveryNote): bool
    {
        if ($deliveryNote->status !== 'pending') {
            return $deliveryNote->status === 'validated';
        }

        return DB::transaction(function () use ($deliveryNote) {
            $lockedNote = DeliveryNote::query()
                ->whereKey($deliveryNote->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedNote->status !== 'pending') {
                return $lockedNote->status === 'validated';
            }

            $lockedNote->load(['items.product', 'purchaseOrder.items.product']);
            $purchaseOrder = PurchaseOrder::query()
                ->whereKey($lockedNote->purchase_order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDeliveryQuantities(
                $purchaseOrder,
                $lockedNote->items->map(fn (DeliveryNoteItem $item) => [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                ])->all(),
                $lockedNote,
            );

            foreach ($lockedNote->items as $item) {
                $this->applyStockDelta($item->product_id, (int) $item->quantity, (float) $item->unit_price);
            }

            $oldStatus = $lockedNote->status;
            $lockedNote->status = 'validated';
            $lockedNote->save();

            $this->recalculatePurchaseOrderStatus($purchaseOrder);

            ActivityLogger::logValidate('Bon de livraison', $lockedNote);

            return true;
        });
    }

    public function cancelDeliveryNote(DeliveryNote $deliveryNote): bool
    {
        if ($deliveryNote->status === 'cancelled') {
            return true;
        }

        return DB::transaction(function () use ($deliveryNote) {
            $lockedNote = DeliveryNote::query()
                ->whereKey($deliveryNote->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedNote->status === 'cancelled') {
                return true;
            }

            $lockedNote->load(['items.product', 'purchaseOrder']);

            if ($lockedNote->status === 'validated') {
                foreach ($lockedNote->items as $item) {
                    $this->applyStockDelta($item->product_id, -(int) $item->quantity, null);
                }
            }

            $oldStatus = $lockedNote->status;
            $lockedNote->status = 'cancelled';
            $lockedNote->save();

            if ($lockedNote->purchaseOrder) {
                $this->recalculatePurchaseOrderStatus(
                    PurchaseOrder::query()->whereKey($lockedNote->purchase_order_id)->lockForUpdate()->firstOrFail(),
                );
            }

            ActivityLogger::logCancel('Bon de livraison', $lockedNote);

            return true;
        });
    }

    /**
     * @param  list<array{product_id:int, quantity:int, unit_price?:float|int|string}>  $items
     *
     * @throws ValidationException
     */
    public function updateValidatedDeliveryNoteItems(DeliveryNote $deliveryNote, array $items): void
    {
        if ($deliveryNote->status !== 'validated') {
            throw ValidationException::withMessages([
                'message' => ['Seuls les bons de livraison validés peuvent être ajustés via cette opération.'],
            ]);
        }

        DB::transaction(function () use ($deliveryNote, $items) {
            $lockedNote = DeliveryNote::query()
                ->whereKey($deliveryNote->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedNote->status !== 'validated') {
                return;
            }

            $lockedNote->load(['items', 'purchaseOrder.items.product']);
            $purchaseOrder = PurchaseOrder::query()
                ->whereKey($lockedNote->purchase_order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldQuantities = $lockedNote->items->pluck('quantity', 'product_id')
                ->map(fn ($qty) => (int) $qty)
                ->all();

            $this->assertDeliveryQuantitiesForValidatedUpdate($purchaseOrder, $lockedNote, $items);

            $newQuantities = [];

            foreach ($items as $itemData) {
                $productId = (int) $itemData['product_id'];
                $newQuantities[$productId] = (int) $itemData['quantity'];
            }

            $allProductIds = array_unique(array_merge(array_keys($oldQuantities), array_keys($newQuantities)));

            foreach ($allProductIds as $productId) {
                $oldQty = $oldQuantities[$productId] ?? 0;
                $newQty = $newQuantities[$productId] ?? 0;
                $delta = $newQty - $oldQty;

                if ($delta !== 0) {
                    $unitPrice = collect($items)->firstWhere('product_id', $productId)['unit_price'] ?? null;
                    $this->applyStockDelta($productId, $delta, $unitPrice !== null ? (float) $unitPrice : null);
                }
            }

            $existingItemIds = $lockedNote->items->pluck('id', 'product_id')->all();

            foreach ($items as $itemData) {
                $productId = (int) $itemData['product_id'];
                $quantity = (int) $itemData['quantity'];
                $unitPrice = (float) $itemData['unit_price'];
                $totalPrice = (float) ($itemData['total_price'] ?? ($quantity * $unitPrice));

                if (isset($existingItemIds[$productId])) {
                    $lockedNote->items()->whereKey($existingItemIds[$productId])->update([
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPrice,
                    ]);
                } else {
                    $lockedNote->items()->create([
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPrice,
                    ]);
                }
            }

            $lockedNote->load('items');
            $lockedNote->calculateTotal();
            $lockedNote->save();

            $this->recalculatePurchaseOrderStatus($purchaseOrder);

            ActivityLogger::logUpdate('Bon de livraison', $lockedNote);
        });
    }

    public function recalculatePurchaseOrderStatus(PurchaseOrder $purchaseOrder): void
    {
        if ($purchaseOrder->status === 'cancelled') {
            return;
        }

        $summary = $this->buildReceiptSummary($purchaseOrder);
        $oldStatus = $purchaseOrder->status;
        $totalOrdered = (int) ($summary['totals']['ordered'] ?? 0);
        $totalDelivered = (int) ($summary['totals']['delivered'] ?? 0);
        $totalRemaining = (int) ($summary['totals']['remaining'] ?? 0);

        if ($totalOrdered > 0 && $totalRemaining <= 0) {
            $purchaseOrder->status = 'received';
        } elseif ($totalDelivered > 0) {
            $purchaseOrder->status = 'partially_received';
        } elseif (in_array($purchaseOrder->status, ['partially_received', 'received'], true)) {
            $purchaseOrder->status = 'confirmed';
        }

        if ($purchaseOrder->status !== $oldStatus) {
            $purchaseOrder->save();

            ActivityLogger::log(
                'update',
                'Bon de commande',
                sprintf(
                    'a recalculé le statut du bon de commande "%s" : %s → %s',
                    $purchaseOrder->po_number,
                    $this->purchaseOrderStatusLabel($oldStatus),
                    $this->purchaseOrderStatusLabel($purchaseOrder->status),
                ),
                $purchaseOrder,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => $purchaseOrder->status],
            );
        }
    }

    /**
     * @param  list<array{product_id:int, quantity:int, unit_price?:float|int|string}>  $items
     *
     * @throws ValidationException
     */
    protected function assertDeliveryQuantitiesForValidatedUpdate(
        PurchaseOrder $purchaseOrder,
        DeliveryNote $deliveryNote,
        array $items,
    ): void {
        $delivered = $this->getDeliveredQuantitiesByProduct($purchaseOrder, $deliveryNote->id);
        $orderedQuantities = $purchaseOrder->items->pluck('quantity', 'product_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();

        $currentQuantities = $deliveryNote->items->pluck('quantity', 'product_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();

        $requestedByProduct = [];

        foreach ($items as $index => $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => ['La quantité à livrer doit être supérieure à 0.'],
                ]);
            }

            $requestedByProduct[$productId] = ($requestedByProduct[$productId] ?? 0) + $quantity;
        }

        foreach ($requestedByProduct as $productId => $requestedQty) {
            $orderedQty = $orderedQuantities[$productId] ?? 0;
            $deliveredQty = $delivered[$productId] ?? 0;
            $currentQty = $currentQuantities[$productId] ?? 0;
            $maxAllowed = max(0, $orderedQty - $deliveredQty) + $currentQty;

            if ($requestedQty > $maxAllowed) {
                throw ValidationException::withMessages([
                    'items' => [
                        sprintf(
                            'Impossible de livrer %d unité(s) : seulement %d unité(s) peuvent être livrées pour ce produit.',
                            $requestedQty,
                            $maxAllowed,
                        ),
                    ],
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $statuses
     * @return array<string, int>
     */
    protected function aggregateItemQuantities(
        PurchaseOrder $purchaseOrder,
        array $statuses,
        ?int $excludeDeliveryNoteId = null,
    ): array {
        $query = DeliveryNoteItem::query()
            ->selectRaw('delivery_note_items.product_id, SUM(delivery_note_items.quantity) as total_quantity')
            ->join('delivery_notes', 'delivery_notes.id', '=', 'delivery_note_items.delivery_note_id')
            ->where('delivery_notes.purchase_order_id', $purchaseOrder->id)
            ->whereIn('delivery_notes.status', $statuses)
            ->groupBy('delivery_note_items.product_id');

        if ($excludeDeliveryNoteId) {
            $query->where('delivery_notes.id', '!=', $excludeDeliveryNoteId);
        }

        return $query->pluck('total_quantity', 'product_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();
    }

    protected function applyStockDelta(int $productId, int $delta, ?float $unitPrice): void
    {
        if ($delta === 0) {
            return;
        }

        $product = Product::query()->whereKey($productId)->lockForUpdate()->first();

        if (! $product) {
            return;
        }

        $oldStock = (int) $product->stock_quantity;

        if ($delta > 0) {
            $product->increment('stock_quantity', $delta);
        } else {
            $product->decrement('stock_quantity', abs($delta));
        }

        if ($unitPrice !== null && $unitPrice > 0 && $delta > 0) {
            $product->cost_price = $unitPrice;
            $product->save();
        }

        $product->refresh();

        ActivityLogger::log(
            'update',
            'Produit',
            sprintf(
                'Stock mis à jour via bon de livraison pour « %s » : %d → %d (%s%d)',
                $product->name,
                $oldStock,
                (int) $product->stock_quantity,
                $delta > 0 ? '+' : '',
                $delta,
            ),
            $product,
            oldValues: ['stock_quantity' => $oldStock],
            newValues: ['stock_quantity' => (int) $product->stock_quantity],
        );
    }

    protected function purchaseOrderStatusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Brouillon',
            'sent' => 'Envoyé',
            'confirmed' => 'Confirmé',
            'partially_received' => 'Partiellement reçu',
            'received' => 'Reçu',
            'cancelled' => 'Annulé',
            default => $status,
        };
    }
}
