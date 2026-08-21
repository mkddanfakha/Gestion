<?php

namespace App\Services;

use App\Models\Product;

class ProductBarcodeService
{
    /**
     * Normalise un code-barres sans supprimer les zéros en tête.
     */
    public function normalize(string $barcode): string
    {
        $normalized = preg_replace('/[\x00-\x1F\x7F\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $barcode) ?? $barcode;

        return trim($normalized);
    }

    /**
     * Recherche un produit actif par code-barres exact.
     */
    public function findByBarcode(string $barcode): ?Product
    {
        $normalized = $this->normalize($barcode);

        if ($normalized === '') {
            return null;
        }

        return Product::query()
            ->with(['category:id,name,color'])
            ->where('is_active', true)
            ->where('barcode', $normalized)
            ->first();
    }

    /**
     * Vérifie qu'aucun autre produit n'utilise déjà ce code-barres.
     */
    public function isBarcodeAvailable(string $barcode, ?int $excludeProductId = null): bool
    {
        $normalized = $this->normalize($barcode);

        if ($normalized === '') {
            return true;
        }

        $query = Product::query()->where('barcode', $normalized);

        if ($excludeProductId !== null) {
            $query->where('id', '!=', $excludeProductId);
        }

        return ! $query->exists();
    }

    /**
     * Formate un produit pour les réponses JSON (autocomplete / scan).
     *
     * @return array<string, mixed>
     */
    public function formatProductPayload(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'price' => (float) $product->price,
            'cost_price' => (float) $product->cost_price,
            'stock_quantity' => (int) $product->stock_quantity,
            'unit' => $product->unit,
            'category' => $product->category,
            'image_url' => $product->getThumbImageUrl(),
        ];
    }
}
