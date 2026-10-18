<?php

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductStock;

function createSaleTestProduct(float $price = 100000, int $stock = 100): Product
{
    $category = Category::create([
        'name' => 'Test',
        'slug' => 'test-'.uniqid(),
    ]);

    $product = Product::create([
        'name' => 'Produit test',
        'sku' => 'SKU-'.uniqid(),
        'price' => $price,
        'stock_quantity' => $stock,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $store = Company::getInstance()->defaultStore()->firstOrFail();
    ProductStock::query()->firstOrCreate(
        ['product_id' => $product->id, 'store_id' => $store->id],
        ['quantity' => $stock],
    );

    return $product;
}

function saleStorePayload(Product $product, array $overrides = []): array
{
    return array_merge([
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 0,
        'items' => [
            [
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => (float) $product->price,
            ],
        ],
    ], $overrides);
}
