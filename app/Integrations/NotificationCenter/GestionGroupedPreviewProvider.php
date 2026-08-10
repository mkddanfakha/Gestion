<?php

namespace App\Integrations\NotificationCenter;

use App\Models\Product;
use App\Models\Sale;
use App\Modules\NotificationCenter\Contracts\GroupedPreviewProviderInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Enrichissement métier Gestion — produits et factures pour alertes groupées.
 */
class GestionGroupedPreviewProvider implements GroupedPreviewProviderInterface
{
    /**
     * @param  list<int|string>  $entityIds
     * @return list<array<string, mixed>>
     */
    public function buildPreviews(string $type, array $entityIds, ?Authenticatable $user = null): array
    {
        if ($entityIds === []) {
            return [];
        }

        return match ($type) {
            'stock_out', 'low_stock', 'product_expired', 'product_expiring' => $this->buildProductPreviews($entityIds, $type),
            'invoice_due' => $this->buildSalePreviews($entityIds, $user),
            default => [],
        };
    }

    /**
     * @param  list<int|string>  $entityIds
     * @return list<array<string, mixed>>
     */
    protected function buildProductPreviews(array $entityIds, string $type): array
    {
        $order = array_values(array_map('intval', $entityIds));
        $position = array_flip($order);

        $products = Product::query()
            ->with('category:id,name')
            ->whereIn('id', $order)
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (Product $product) => $position[$product->id] ?? PHP_INT_MAX)
            ->values();

        return $products->map(fn (Product $product) => $this->mapProductPreview($product, $type))->all();
    }

    /**
     * @param  list<int|string>  $entityIds
     * @return list<array<string, mixed>>
     */
    protected function buildSalePreviews(array $entityIds, ?Authenticatable $user): array
    {
        $order = array_values(array_map('intval', $entityIds));
        $position = array_flip($order);

        $sales = Sale::query()
            ->with('customer:id,name')
            ->whereIn('id', $order)
            ->get()
            ->sortBy(fn (Sale $sale) => $position[$sale->id] ?? PHP_INT_MAX)
            ->values();

        return $sales->map(fn (Sale $sale) => $this->mapSalePreview($sale))->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapSalePreview(Sale $sale): array
    {
        $dueDate = $sale->due_date instanceof Carbon ? $sale->due_date->copy()->startOfDay() : null;
        $daysUntilDue = $dueDate ? (int) now()->startOfDay()->diffInDays($dueDate, false) : null;
        $remainingAmount = (float) ($sale->remaining_amount ?? $sale->total_amount ?? 0);

        return [
            'id' => $sale->id,
            'name' => 'Facture ' . $sale->sale_number,
            'reference' => $sale->sale_number,
            'customer' => $sale->customer?->name ?? 'Client anonyme',
            'remaining_amount' => $remainingAmount,
            'due_date' => $dueDate?->format('d/m/Y'),
            'due_date_iso' => $dueDate?->format('Y-m-d'),
            'days_until_due' => $daysUntilDue,
            'status' => $this->saleDueStatusLabel($daysUntilDue),
            'url' => route('sales.show', $sale->id),
        ];
    }

    protected function saleDueStatusLabel(?int $daysUntilDue): string
    {
        if ($daysUntilDue === null) {
            return 'Échéance de facture';
        }

        if ($daysUntilDue === 0) {
            return 'Échéance aujourd\'hui';
        }

        if ($daysUntilDue > 0) {
            return $daysUntilDue === 1
                ? 'Il reste 1 jour avant l\'échéance'
                : "Il reste {$daysUntilDue} jours avant l'échéance";
        }

        $overdueDays = abs($daysUntilDue);

        return $overdueDays === 1
            ? 'Échéance dépassée depuis 1 jour'
            : "Échéance dépassée depuis {$overdueDays} jours";
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapProductPreview(Product $product, string $type): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'reference' => $product->sku ?: $product->barcode,
            'sku' => $product->sku,
            'stock' => $product->stock_quantity,
            'stock_quantity' => $product->stock_quantity,
            'minimum_stock' => $product->min_stock_level,
            'min_stock_level' => $product->min_stock_level,
            'unit' => $product->unit,
            'image_url' => $product->getThumbImageUrl(),
            'category' => $product->category?->name,
            'expiration_date' => $product->expiration_date?->format('Y-m-d'),
            'days_until_expiration' => $product->days_until_expiration,
            'status' => $this->statusLabel($type, $product),
            'url' => route('products.show', $product->id),
        ];
    }

    protected function statusLabel(string $type, Product $product): string
    {
        return match ($type) {
            'stock_out' => 'Rupture de stock',
            'low_stock' => 'Stock faible',
            'product_expired' => 'Produit expiré',
            'product_expiring' => 'Bientôt expiré',
            default => 'Alerte produit',
        };
    }
}
