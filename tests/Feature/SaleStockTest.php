<?php

use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function saleStockMainStore(): Store
{
    return Company::getInstance()->defaultStore()->firstOrFail();
}

function ensureSaleStock(Product $product, int $quantity): ProductStock
{
    $store = saleStockMainStore();

    $stock = ProductStock::query()->firstOrCreate(
        ['product_id' => $product->id, 'store_id' => $store->id],
        ['quantity' => $quantity],
    );

    if ((int) $stock->quantity !== $quantity) {
        $stock->update(['quantity' => $quantity]);
    }

    $product->update(['stock_quantity' => $quantity]);

    return $stock->fresh();
}

function getSaleStockQuantity(Product $product): int
{
    $store = saleStockMainStore();

    return (int) ProductStock::query()
        ->where('product_id', $product->id)
        ->where('store_id', $store->id)
        ->value('quantity');
}

function createSaleStockProduct(string $name, int $stock, float $price = 10000): Product
{
    $category = Category::create([
        'name' => 'Cat '.uniqid(),
        'slug' => 'cat-'.uniqid(),
    ]);

    $product = Product::create([
        'name' => $name,
        'sku' => 'SKU-'.uniqid(),
        'price' => $price,
        'stock_quantity' => $stock,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    ensureSaleStock($product, $stock);

    return $product;
}

function saleStockPayload(Product $product, int $quantity = 1, array $overrides = []): array
{
    return array_merge([
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 0,
        'items' => [
            [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => (float) $product->price,
            ],
        ],
    ], $overrides);
}

function createSaleViaHttp(User $user, array $payload): Sale
{
    $user->fresh();
    test()->actingAs($user)->post(route('sales.store'), $payload)->assertRedirect();

    return Sale::query()->latest('id')->firstOrFail();
}

test('creating sale decreases product stock on main store', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleStockProduct('Riz', 20);

    createSaleViaHttp($admin, saleStockPayload($product, 3));

    expect(getSaleStockQuantity($product))->toBe(17)
        ->and($product->fresh()->stock_quantity)->toBe(17);
});

test('creating sale records sale stock movement with before and after', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleStockProduct('Huile', 20);

    $sale = createSaleViaHttp($admin, saleStockPayload($product, 3));

    $movement = StockMovement::query()
        ->where('product_id', $product->id)
        ->where('type', StockMovementType::Sale)
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->quantity)->toBe(-3)
        ->and($movement->quantity_before)->toBe(20)
        ->and($movement->quantity_after)->toBe(17)
        ->and($movement->reference_type)->toBe($sale->getMorphClass())
        ->and($movement->reference_id)->toBe($sale->id);
});

test('creating sale syncs products stock quantity mirror', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleStockProduct('Sucre', 50);

    createSaleViaHttp($admin, saleStockPayload($product, 12));

    $store = saleStockMainStore();

    expect($product->fresh()->stock_quantity)->toBe(
        (int) ProductStock::query()
            ->where('product_id', $product->id)
            ->where('store_id', $store->id)
            ->value('quantity'),
    );
});

test('sale is rejected when stock is insufficient', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleStockProduct('Farine', 4);

    $this->actingAs($admin)->post(route('sales.store'), saleStockPayload($product, 5))
        ->assertSessionHasErrors(['items.0.quantity']);

    expect(Sale::query()->count())->toBe(0)
        ->and(StockMovement::query()->where('product_id', $product->id)->count())->toBe(0)
        ->and($product->fresh()->stock_quantity)->toBe(4)
        ->and(getSaleStockQuantity($product))->toBe(4);
});

test('multi product sale decreases all stocks atomically', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $productA = createSaleStockProduct('Produit A', 10);
    $productB = createSaleStockProduct('Produit B', 8);

    $payload = [
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 0,
        'items' => [
            ['product_id' => $productA->id, 'quantity' => 5, 'unit_price' => 1000],
            ['product_id' => $productB->id, 'quantity' => 3, 'unit_price' => 2000],
        ],
    ];

    createSaleViaHttp($admin, $payload);

    expect(getSaleStockQuantity($productA))->toBe(5)
        ->and(getSaleStockQuantity($productB))->toBe(5)
        ->and(StockMovement::query()->where('type', StockMovementType::Sale)->count())->toBe(2);
});

test('multi product sale rolls back when one product has insufficient stock', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $productA = createSaleStockProduct('OK', 10);
    $productB = createSaleStockProduct('KO', 2);

    $payload = [
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 0,
        'items' => [
            ['product_id' => $productA->id, 'quantity' => 3, 'unit_price' => 1000],
            ['product_id' => $productB->id, 'quantity' => 5, 'unit_price' => 1000],
        ],
    ];

    $this->actingAs($admin)->post(route('sales.store'), $payload)
        ->assertSessionHasErrors();

    expect(Sale::query()->count())->toBe(0)
        ->and(getSaleStockQuantity($productA))->toBe(10)
        ->and(getSaleStockQuantity($productB))->toBe(2)
        ->and(StockMovement::query()->count())->toBe(0);
});

test('updating sale with increased quantity creates additional sale movement', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleStockProduct('Café', 20);
    $sale = createSaleViaHttp($admin, saleStockPayload($product, 5));

    $movementCountBefore = StockMovement::query()->where('product_id', $product->id)->count();

    $this->actingAs($admin)->put(route('sales.update', $sale), saleStockPayload($product, 7))
        ->assertRedirect();

    $deltaMovement = StockMovement::query()
        ->where('product_id', $product->id)
        ->where('type', StockMovementType::Sale)
        ->latest('id')
        ->first();

    expect(StockMovement::query()->where('product_id', $product->id)->count())->toBe($movementCountBefore + 1)
        ->and($deltaMovement->quantity)->toBe(-2)
        ->and(getSaleStockQuantity($product))->toBe(13);
});

test('updating sale with decreased quantity creates sale cancel movement', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleStockProduct('Thé', 20);
    $sale = createSaleViaHttp($admin, saleStockPayload($product, 7));

    $this->actingAs($admin)->put(route('sales.update', $sale), saleStockPayload($product, 4))
        ->assertRedirect();

    $cancelMovement = StockMovement::query()
        ->where('product_id', $product->id)
        ->where('type', StockMovementType::SaleCancel)
        ->first();

    expect($cancelMovement)->not->toBeNull()
        ->and($cancelMovement->quantity)->toBe(3)
        ->and(getSaleStockQuantity($product))->toBe(16);
});

test('updating sale with removed product restores its stock', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $productA = createSaleStockProduct('A', 10);
    $productB = createSaleStockProduct('B', 10);
    $sale = createSaleViaHttp($admin, [
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 0,
        'items' => [
            ['product_id' => $productA->id, 'quantity' => 5, 'unit_price' => 1000],
            ['product_id' => $productB->id, 'quantity' => 3, 'unit_price' => 1000],
        ],
    ]);

    $this->actingAs($admin)->put(route('sales.update', $sale), saleStockPayload($productA, 5))
        ->assertRedirect();

    expect(StockMovement::query()
        ->where('product_id', $productB->id)
        ->where('type', StockMovementType::SaleCancel)
        ->exists())->toBeTrue()
        ->and(getSaleStockQuantity($productB))->toBe(10)
        ->and(getSaleStockQuantity($productA))->toBe(5);
});

test('updating sale with new product decreases its stock', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $productA = createSaleStockProduct('A', 10);
    $productC = createSaleStockProduct('C', 10);
    $sale = createSaleViaHttp($admin, saleStockPayload($productA, 5));

    $this->actingAs($admin)->put(route('sales.update', $sale), [
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 0,
        'items' => [
            ['product_id' => $productA->id, 'quantity' => 5, 'unit_price' => 1000],
            ['product_id' => $productC->id, 'quantity' => 2, 'unit_price' => 1000],
        ],
    ])->assertRedirect();

    expect(StockMovement::query()
        ->where('product_id', $productC->id)
        ->where('type', StockMovementType::Sale)
        ->exists())->toBeTrue()
        ->and(getSaleStockQuantity($productC))->toBe(8);
});

test('updating sale with unchanged quantities creates no new movement', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleStockProduct('Stable', 20);
    $sale = createSaleViaHttp($admin, saleStockPayload($product, 5));

    $movementCount = StockMovement::query()->where('product_id', $product->id)->count();

    $this->actingAs($admin)->put(route('sales.update', $sale), saleStockPayload($product, 5))
        ->assertRedirect();

    expect(StockMovement::query()->where('product_id', $product->id)->count())->toBe($movementCount)
        ->and(getSaleStockQuantity($product))->toBe(15);
});

test('deleting sale restores stock and creates sale cancel movement', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleStockProduct('Lait', 20);
    $sale = createSaleViaHttp($admin, saleStockPayload($product, 5));

    $this->actingAs($admin)->delete(route('sales.destroy', $sale))->assertRedirect();

    $cancelMovement = StockMovement::query()
        ->where('product_id', $product->id)
        ->where('type', StockMovementType::SaleCancel)
        ->first();

    expect(Sale::query()->whereKey($sale->id)->exists())->toBeFalse()
        ->and($cancelMovement)->not->toBeNull()
        ->and($cancelMovement->quantity)->toBe(5)
        ->and(getSaleStockQuantity($product))->toBe(20);
});

test('deleting sale preserves original sale movements in immutable journal', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleStockProduct('Eau', 20);
    $sale = createSaleViaHttp($admin, saleStockPayload($product, 5));

    $originalSaleMovementId = StockMovement::query()
        ->where('type', StockMovementType::Sale)
        ->where('reference_id', $sale->id)
        ->value('id');

    $this->actingAs($admin)->delete(route('sales.destroy', $sale))->assertRedirect();

    expect(StockMovement::query()->whereKey($originalSaleMovementId)->exists())->toBeTrue()
        ->and(StockMovement::query()->where('type', StockMovementType::SaleCancel)->count())->toBe(1);
});

test('quote conversion to sale creates single sale movement per product', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $productA = createSaleStockProduct('Devis A', 10);
    $productB = createSaleStockProduct('Devis B', 8);

    $quote = Quote::create([
        'quote_number' => Quote::generateQuoteNumber(),
        'user_id' => $admin->id,
        'subtotal' => 5000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 5000,
        'status' => 'sent',
    ]);

    QuoteItem::create([
        'quote_id' => $quote->id,
        'product_id' => $productA->id,
        'quantity' => 2,
        'unit_price' => 1000,
        'total_price' => 2000,
    ]);

    QuoteItem::create([
        'quote_id' => $quote->id,
        'product_id' => $productB->id,
        'quantity' => 3,
        'unit_price' => 1000,
        'total_price' => 3000,
    ]);

    $this->actingAs($admin)->post(route('quotes.convert-to-sale', $quote))->assertRedirect();

    expect(Sale::query()->count())->toBe(1)
        ->and(StockMovement::query()->where('type', StockMovementType::Sale)->count())->toBe(2)
        ->and(getSaleStockQuantity($productA))->toBe(8)
        ->and(getSaleStockQuantity($productB))->toBe(5);
});

test('concurrent sales on limited stock allow only one success', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleStockProduct('Limité', 5);

    createSaleViaHttp($admin, saleStockPayload($product, 3));

    $this->actingAs($admin)->post(route('sales.store'), saleStockPayload($product, 3))
        ->assertSessionHasErrors(['items.0.quantity']);

    expect(Sale::query()->count())->toBe(1)
        ->and(getSaleStockQuantity($product))->toBe(2)
        ->and(StockMovement::query()->where('type', StockMovementType::Sale)->count())->toBe(1);
});
