<?php

use App\Enums\InventoryScopeType;
use App\Enums\InventorySessionStatus;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventorySession;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function inventoryIdorPermissions(array $actions = ['view', 'create', 'count', 'submit', 'review', 'validate', 'apply', 'cancel', 'close']): User
{
    foreach ($actions as $action) {
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

function inventoryIdorPrimaryProduct(): Product
{
    Company::getInstance();
    $store = Company::getInstance()->defaultStore()->firstOrFail();
    $category = Category::create(['name' => 'Cat IDOR '.uniqid()]);

    $product = Product::create([
        'name' => 'Produit IDOR '.uniqid(),
        'sku' => 'ID'.random_int(1000, 9999),
        'barcode' => (string) random_int(1000000000000, 9999999999999),
        'price' => 1000,
        'stock_quantity' => 10,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    ProductStock::query()->updateOrCreate(
        ['product_id' => $product->id, 'store_id' => $store->id],
        ['quantity' => 10],
    );

    return $product;
}

/**
 * @return array{session: InventorySession, item: InventoryItem, product: Product}
 */
function inventoryIdorForeignSession(InventorySessionStatus $status = InventorySessionStatus::Counting): array
{
    Company::getInstance();
    $otherCompany = Company::create(['name' => 'Entreprise B '.uniqid(), 'email' => uniqid().'@b.test']);
    $otherStore = Store::ensureDefaultForCompany($otherCompany);
    $product = inventoryIdorPrimaryProduct();

    $session = InventorySession::query()->create([
        'company_id' => $otherCompany->id,
        'store_id' => $otherStore->id,
        'reference' => 'INV-B-'.random_int(100000, 999999),
        'status' => $status,
        'scope_type' => InventoryScopeType::Complete,
        'created_by' => User::factory()->create()->id,
    ]);

    $item = InventoryItem::query()->create([
        'inventory_session_id' => $session->id,
        'product_id' => $product->id,
        'stock_snapshot' => 10,
        'quantity_counted' => $status === InventorySessionStatus::Counting ? null : 8,
    ]);

    return compact('session', 'item', 'product');
}

test('inventory routes reject foreign company session access with 404', function (string $method, string $routeName) {
    $user = inventoryIdorPermissions();
    ['session' => $foreignSession, 'item' => $foreignItem, 'product' => $foreignProduct] = inventoryIdorForeignSession();

    $routeParams = ['session' => $foreignSession->id];

    if ($routeName === 'inventory.items.count') {
        $routeParams['item'] = $foreignItem->id;
    }

    $payload = match ($routeName) {
        'inventory.scan' => ['barcode' => $foreignProduct->barcode],
        'inventory.items.count' => ['quantity_counted' => 5],
        default => [],
    };

    $response = test()->actingAs($user)->json(
        $method,
        route($routeName, $routeParams),
        $payload,
    );

    $response->assertNotFound();
})->with([
    ['GET', 'inventory.show'],
    ['POST', 'inventory.scan'],
    ['POST', 'inventory.items.count'],
    ['POST', 'inventory.submit'],
    ['POST', 'inventory.validate'],
    ['POST', 'inventory.apply'],
    ['POST', 'inventory.cancel'],
    ['POST', 'inventory.close'],
]);

test('inventory foreign item on valid session route returns 404', function () {
    $user = inventoryIdorPermissions();
    Company::getInstance();
    $store = Company::getInstance()->defaultStore()->firstOrFail();
    $product = inventoryIdorPrimaryProduct();

    $ownSession = InventorySession::query()->create([
        'company_id' => Company::getInstance()->id,
        'store_id' => $store->id,
        'reference' => 'INV-A-'.random_int(100000, 999999),
        'status' => InventorySessionStatus::Counting,
        'scope_type' => InventoryScopeType::Complete,
        'created_by' => $user->id,
    ]);

    InventoryItem::query()->create([
        'inventory_session_id' => $ownSession->id,
        'product_id' => $product->id,
        'stock_snapshot' => 10,
    ]);

    ['item' => $foreignItem] = inventoryIdorForeignSession();

    test()->actingAs($user)->postJson(route('inventory.items.count', [
        'session' => $ownSession->id,
        'item' => $foreignItem->id,
    ]), [
        'quantity_counted' => 5,
    ])->assertNotFound();
});
