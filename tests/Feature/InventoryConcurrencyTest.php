<?php

use App\Enums\InventoryScopeType;
use App\Enums\InventorySessionStatus;
use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventorySession;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\InventoryApplicationService;
use App\Services\InventorySessionService;
use App\Services\SaleStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function inventoryConcurrencyPermissions(): User
{
    foreach (['view', 'create', 'count', 'submit', 'review', 'validate', 'apply', 'close'] as $action) {
        Permission::firstOrCreate(
            ['resource' => 'inventory', 'action' => $action],
            [
                'name' => Permission::generateName('inventory', $action),
                'description' => "inventory.{$action}",
            ],
        );
    }

    $user = User::factory()->create(['role' => User::ROLE_GESTIONNAIRE]);
    $user->permissions()->sync(
        Permission::query()->where('resource', 'inventory')->pluck('id'),
    );

    return $user;
}

function inventoryConcurrencyProduct(int $stock = 5): Product
{
    $store = Company::getInstance()->defaultStore()->firstOrFail();
    $category = Category::create(['name' => 'Conc '.uniqid()]);

    $product = Product::create([
        'name' => 'Produit conc '.uniqid(),
        'sku' => 'CC'.random_int(1000, 9999),
        'price' => 1000,
        'stock_quantity' => $stock,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    ProductStock::query()->updateOrCreate(
        ['product_id' => $product->id, 'store_id' => $store->id],
        ['quantity' => $stock],
    );

    return $product;
}

function inventoryConcurrencyValidatedSession(User $user, Product $product, int $countedQuantity): InventorySession
{
    $sessionService = app(InventorySessionService::class);
    $session = $sessionService->create([
        'name' => 'Conc session '.uniqid(),
        'scope_type' => InventoryScopeType::Complete,
    ], $user);
    $session = $sessionService->start($session, $user);
    $item = $session->items()->firstWhere('product_id', $product->id);
    $sessionService->countItem($session, $item, $countedQuantity, $user);
    $session = $sessionService->submit($session, $user);

    return $sessionService->validate($session, $user);
}

/**
 * Scénario séquentiel (pas de multi-processus) :
 * stock initial 5, inventaire compté 3 validé, vente -3, apply inventaire.
 * Le delta doit utiliser le stock courant (2), pas le snapshot (5).
 */
test('inventory apply after sale uses current stock not snapshot', function () {
    $user = inventoryConcurrencyPermissions();
    test()->actingAs($user);
    $product = inventoryConcurrencyProduct(5);
    $session = inventoryConcurrencyValidatedSession($user, $product, 3);

    $sale = Sale::create([
        'sale_number' => Sale::generateSaleNumber(),
        'user_id' => $user->id,
        'subtotal' => 1000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 1000,
        'remaining_amount' => 0,
        'payment_status' => 'paid',
        'total_amount' => 1000,
        'status' => 'completed',
        'payment_method' => 'cash',
    ]);

    app(SaleStockService::class)->applySaleCreation($sale, [$product->id => 3]);

    expect((int) ProductStock::query()->where('product_id', $product->id)->value('quantity'))->toBe(2);

    $result = app(InventoryApplicationService::class)->apply($session, $user);

    expect($result['session']->status)->toBe(InventorySessionStatus::Applied)
        ->and((int) ProductStock::query()->where('product_id', $product->id)->value('quantity'))->toBe(3)
        ->and((int) $product->fresh()->stock_quantity)->toBe(3);

    $adjustmentMovement = StockMovement::query()
        ->where('product_id', $product->id)
        ->where('type', StockMovementType::InventoryAdjustment)
        ->latest('id')
        ->first();

    expect($adjustmentMovement)->not->toBeNull()
        ->and($adjustmentMovement->quantity)->toBe(1)
        ->and($adjustmentMovement->metadata['delta_from_current'] ?? null)->toBe(1)
        ->and($adjustmentMovement->metadata['stock_before_apply'] ?? null)->toBe(2);
});

test('inventory apply with unchanged counted quantity after stock movement records zero net delta items', function () {
    $user = inventoryConcurrencyPermissions();
    test()->actingAs($user);
    $product = inventoryConcurrencyProduct(10);
    $session = inventoryConcurrencyValidatedSession($user, $product, 10);

    $sale = Sale::create([
        'sale_number' => Sale::generateSaleNumber(),
        'user_id' => $user->id,
        'subtotal' => 1000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 1000,
        'remaining_amount' => 0,
        'payment_status' => 'paid',
        'total_amount' => 1000,
        'status' => 'completed',
        'payment_method' => 'cash',
    ]);

    app(SaleStockService::class)->applySaleCreation($sale, [$product->id => 4]);

    $result = app(InventoryApplicationService::class)->apply($session, $user);

    expect($result['summary']['adjusted_items'])->toBe(1)
        ->and($result['summary']['total_positive_quantity'])->toBe(4)
        ->and((int) ProductStock::query()->where('product_id', $product->id)->value('quantity'))->toBe(10);
});

/*
 * Concurrence multi-processus non exécutable dans cet environnement PHPUnit mono-processus.
 * Les scénarios séquentiels ci-dessus couvrent la cohérence delta/stock courant.
 */
