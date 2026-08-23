<?php

use App\Auth\RolePresets;
use App\Models\Category;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Storage::fake('public');
});

function sensitiveRoutesUserWithPermissions(array $permissionNames, string $role = User::ROLE_USER): User
{
    $user = User::factory()->create(['role' => $role]);
    $user->permissions()->sync(
        Permission::query()->whereIn('name', $permissionNames)->pluck('id'),
    );

    return $user->fresh();
}

function sensitiveRoutesPendingDeliveryNote(User $creator): DeliveryNote
{
    $supplier = Supplier::create(['name' => 'Fournisseur Test', 'status' => 'active']);
    $category = Category::create(['name' => 'Cat', 'slug' => 'cat-'.uniqid()]);

    $product = Product::create([
        'name' => 'Produit test',
        'sku' => 'SKU-'.uniqid(),
        'price' => 1000,
        'cost_price' => 800,
        'stock_quantity' => 10,
        'min_stock_level' => 1,
        'unit' => 'u',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $store = Company::getInstance()->defaultStore()->firstOrFail();
    ProductStock::query()->firstOrCreate(
        ['product_id' => $product->id, 'store_id' => $store->id],
        ['quantity' => 10],
    );

    $purchaseOrder = PurchaseOrder::create([
        'po_number' => PurchaseOrder::generatePONumber(),
        'supplier_id' => $supplier->id,
        'user_id' => $creator->id,
        'order_date' => now()->toDateString(),
        'status' => 'confirmed',
        'subtotal' => 8000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 8000,
    ]);

    PurchaseOrderItem::create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'quantity' => 10,
        'unit_price' => 800,
        'total_price' => 8000,
    ]);

    $deliveryNote = DeliveryNote::create([
        'delivery_number' => DeliveryNote::generateDeliveryNumber(),
        'purchase_order_id' => $purchaseOrder->id,
        'supplier_id' => $supplier->id,
        'user_id' => $creator->id,
        'delivery_date' => now()->toDateString(),
        'status' => 'pending',
        'subtotal' => 8000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 8000,
    ]);

    DeliveryNoteItem::create([
        'delivery_note_id' => $deliveryNote->id,
        'product_id' => $product->id,
        'quantity' => 10,
        'unit_price' => 800,
        'total_price' => 8000,
    ]);

    return $deliveryNote->fresh();
}

// --- Produits : upload-image ---

test('upload image autorise avec products.create', function () {
    $user = sensitiveRoutesUserWithPermissions(['products.create']);

    $this->actingAs($user)
        ->postJson(route('products.upload-image'), [
            'image' => UploadedFile::fake()->image('product.jpg'),
        ])
        ->assertOk()
        ->assertJsonStructure(['path', 'url']);
});

test('upload image autorise avec products.update', function () {
    $user = sensitiveRoutesUserWithPermissions(['products.update']);

    $this->actingAs($user)
        ->postJson(route('products.upload-image'), [
            'image' => UploadedFile::fake()->image('product.jpg'),
        ])
        ->assertOk();
});

test('upload image autorise avec permission legacy products.edit', function () {
    $user = sensitiveRoutesUserWithPermissions(['products.edit']);

    $this->actingAs($user)
        ->postJson(route('products.upload-image'), [
            'image' => UploadedFile::fake()->image('product.jpg'),
        ])
        ->assertOk();
});

test('upload image refuse sans permission produit', function () {
    $user = sensitiveRoutesUserWithPermissions(['products.view']);

    $this->actingAs($user)
        ->postJson(route('products.upload-image'), [
            'image' => UploadedFile::fake()->image('product.jpg'),
        ])
        ->assertForbidden();
});

test('upload image refuse utilisateur non authentifie', function () {
    $this->postJson(route('products.upload-image'), [
        'image' => UploadedFile::fake()->image('product.jpg'),
    ])->assertUnauthorized();
});

// --- Produits : generate-sku ---

test('generate sku autorise avec products.create', function () {
    $user = sensitiveRoutesUserWithPermissions(['products.create']);

    $this->actingAs($user)
        ->postJson(route('products.generate-sku'), ['name' => 'Nouveau produit'])
        ->assertOk()
        ->assertJsonStructure(['sku']);
});

test('generate sku autorise avec products.update', function () {
    $user = sensitiveRoutesUserWithPermissions(['products.update']);

    $this->actingAs($user)
        ->postJson(route('products.generate-sku'), ['name' => 'Produit modifié'])
        ->assertOk()
        ->assertJsonStructure(['sku']);
});

test('generate sku refuse sans permission produit', function () {
    $user = sensitiveRoutesUserWithPermissions(['products.view']);

    $this->actingAs($user)
        ->postJson(route('products.generate-sku'), ['name' => 'Test'])
        ->assertForbidden();
});

test('gestionnaire preset peut generer un sku', function () {
    $user = User::factory()->create(['role' => User::ROLE_GESTIONNAIRE]);
    $user->permissions()->sync(RolePresets::permissionIds(User::ROLE_GESTIONNAIRE));

    $this->actingAs($user->fresh())
        ->postJson(route('products.generate-sku'), ['name' => 'Stock item'])
        ->assertOk();
});

// --- Bons de livraison : validate ---

test('validate delivery note autorise avec delivery-notes.validate', function () {
    $user = sensitiveRoutesUserWithPermissions(['delivery-notes.validate']);
    $deliveryNote = sensitiveRoutesPendingDeliveryNote($user);

    $this->actingAs($user)
        ->post(route('delivery-notes.validate', $deliveryNote))
        ->assertRedirect(route('delivery-notes.show', $deliveryNote));
});

test('validate delivery note refuse sans permission', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $user = sensitiveRoutesUserWithPermissions(['delivery-notes.view']);
    $deliveryNote = sensitiveRoutesPendingDeliveryNote($admin);

    $this->actingAs($user)
        ->post(route('delivery-notes.validate', $deliveryNote))
        ->assertForbidden();
});

test('validate delivery note autorise admin via bypass', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $deliveryNote = sensitiveRoutesPendingDeliveryNote($admin);

    $this->actingAs($admin)
        ->post(route('delivery-notes.validate', $deliveryNote))
        ->assertRedirect(route('delivery-notes.show', $deliveryNote));
});

test('gestionnaire preset peut valider un bon de livraison', function () {
    $user = User::factory()->create(['role' => User::ROLE_GESTIONNAIRE]);
    $user->permissions()->sync(RolePresets::permissionIds(User::ROLE_GESTIONNAIRE));
    $deliveryNote = sensitiveRoutesPendingDeliveryNote($user);

    $this->actingAs($user->fresh())
        ->post(route('delivery-notes.validate', $deliveryNote))
        ->assertRedirect(route('delivery-notes.show', $deliveryNote));
});

// --- Routes /dev ---

test('routes dev non enregistrees hors environnement local', function () {
    expect(Route::has('dev.barcode-reader-test'))->toBeFalse();
});

test('routes dev inaccessibles hors environnement local', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

    $this->actingAs($admin)
        ->get('/dev/barcode-reader-test')
        ->assertNotFound();
});

// --- Notifications test ---

test('notification test refuse utilisateur non admin', function () {
    $user = sensitiveRoutesUserWithPermissions(['dashboard.view']);

    $this->actingAs($user)
        ->postJson(route('notification-center.test'))
        ->assertForbidden();
});

test('notification test autorise admin', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

    $this->actingAs($admin)
        ->postJson(route('notification-center.test'))
        ->assertOk()
        ->assertJson(['success' => true]);
});

// --- Isolation mono-entreprise (ressource inexistante) ---

test('validate delivery note sur ressource inexistante retourne 404', function () {
    $user = sensitiveRoutesUserWithPermissions(['delivery-notes.validate']);

    $this->actingAs($user)
        ->post(route('delivery-notes.validate', ['deliveryNote' => 999999]))
        ->assertNotFound();
});

test('company singleton accessible uniquement avec permission', function () {
    Company::getInstance();

    $user = sensitiveRoutesUserWithPermissions(['products.view']);

    $this->actingAs($user)
        ->post(route('company.logo.upload'), [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])
        ->assertForbidden();
});
