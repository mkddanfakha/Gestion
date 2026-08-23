<?php

use App\Auth\AssignablePermissionResolver;
use App\Auth\AuthorizationService;
use App\Auth\LegacyPermissionMigrator;
use App\Auth\RolePresets;
use App\Enums\InventoryScopeType;
use App\Enums\InventorySessionStatus;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventorySession;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\InventorySessionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function n3UserWithNames(array $names, string $role = User::ROLE_USER): User
{
    $user = User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->permissions()->sync(
        Permission::query()->whereIn('name', $names)->pluck('id'),
    );

    return $user->fresh();
}

function n3CreateProduct(): Product
{
    $category = Category::create([
        'name' => 'Cat N3 '.uniqid(),
        'slug' => 'cat-n3-'.uniqid(),
    ]);

    return Product::create([
        'name' => 'Produit N3 '.uniqid(),
        'sku' => 'N3'.random_int(1000, 9999),
        'price' => 100,
        'cost_price' => 80,
        'stock_quantity' => 5,
        'min_stock_level' => 0,
        'unit' => 'u',
        'category_id' => $category->id,
        'is_active' => true,
    ]);
}

function n3SessionInReview(User $actor): InventorySession
{
    Company::getInstance();
    $store = Company::getInstance()->defaultStore()->firstOrFail();
    $category = Category::create(['name' => 'Inv N3 '.uniqid()]);
    $product = Product::create([
        'name' => 'Inv prod '.uniqid(),
        'sku' => 'IN'.random_int(1000, 9999),
        'price' => 1000,
        'stock_quantity' => 10,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);
    ProductStock::query()->firstOrCreate(
        ['product_id' => $product->id, 'store_id' => $store->id],
        ['quantity' => 10],
    );

    $service = app(InventorySessionService::class);
    $session = $service->create([
        'name' => 'Session N3 '.uniqid(),
        'scope_type' => InventoryScopeType::Complete,
    ], $actor);
    $session = $service->start($session, $actor);
    foreach ($session->fresh('items')->items as $item) {
        $service->countItem($session, $item, $item->stock_snapshot, $actor);
    }

    return $service->submit($session, $actor);
}

test('utilisateur avec products.update peut modifier un produit', function () {
    $user = n3UserWithNames(['products.view', 'products.update']);
    $product = n3CreateProduct();

    $this->actingAs($user)
        ->get(route('products.edit', $product))
        ->assertOk();
});

test('utilisateur avec uniquement products.edit legacy peut encore modifier un produit', function () {
    $user = n3UserWithNames(['products.view', 'products.edit']);
    $product = n3CreateProduct();

    $this->actingAs($user)
        ->get(route('products.edit', $product))
        ->assertOk();

    expect(app(AuthorizationService::class)->allows($user, 'products.update'))->toBeTrue();
});

test('utilisateur avec inventory.reopen peut reouvrir', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $session = n3SessionInReview($admin);
    $user = n3UserWithNames(['inventory.view', 'inventory.reopen']);

    $this->actingAs($user)
        ->post(route('inventory.reopen', $session))
        ->assertRedirect();

    expect($session->fresh()->status)->toBe(InventorySessionStatus::Counting);
});

test('utilisateur avec uniquement inventory.review legacy peut encore reouvrir', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $session = n3SessionInReview($admin);
    $user = n3UserWithNames(['inventory.view', 'inventory.review']);

    $this->actingAs($user)
        ->post(route('inventory.reopen', $session))
        ->assertRedirect();

    expect($session->fresh()->status)->toBe(InventorySessionStatus::Counting);
});

test('presets et grille admin ne proposent pas edit ni review', function () {
    foreach ([User::ROLE_VENDEUR, User::ROLE_GESTIONNAIRE] as $role) {
        foreach (RolePresets::permissionNames($role) as $name) {
            expect(str_ends_with($name, '.edit'))->toBeFalse();
            expect($name)->not->toBe('inventory.review');
        }
    }

    $gridNames = AssignablePermissionResolver::adminGridByResource()
        ->flatten(1)
        ->pluck('name')
        ->all();

    expect($gridNames)->toContain('products.update')
        ->and($gridNames)->toContain('inventory.reopen')
        ->and($gridNames)->not->toContain('products.edit')
        ->and($gridNames)->not->toContain('inventory.review');
});

test('creation utilisateur custom canonise un id legacy soumis', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $legacyId = Permission::query()->where('name', 'products.edit')->value('id');
    $viewId = Permission::query()->where('name', 'products.view')->value('id');

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'User N3 Custom',
            'email' => 'n3-custom-'.uniqid().'@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_USER,
            'is_active' => true,
            'permissions' => [$legacyId, $viewId],
        ])
        ->assertRedirect(route('admin.users.index'));

    $created = User::query()->where('email', 'like', 'n3-custom-%')->latest('id')->first();
    $names = $created->permissions()->pluck('name')->sort()->values()->all();

    expect($names)->toEqualCanonicalizing(['products.update', 'products.view'])
        ->and($names)->not->toContain('products.edit');
});

test('migrate-legacy-permissions migre et reste idempotente', function () {
    $user = n3UserWithNames(['sales.edit', 'inventory.review']);
    $migrator = app(LegacyPermissionMigrator::class);

    $first = $migrator->migrate(dryRun: false);
    $second = $migrator->migrate(dryRun: false);

    expect($first['legacy_removed'])->toBe(2)
        ->and($second['legacy_removed'])->toBe(0)
        ->and($migrator->permissionNamesFor($user->fresh()))
        ->toEqualCanonicalizing(['sales.update', 'inventory.reopen']);
});

test('rollback migration legacy restaure letat initial', function () {
    $user = n3UserWithNames(['company.edit']);
    $before = app(LegacyPermissionMigrator::class)->permissionNamesFor($user);

    expect(fn () => app(LegacyPermissionMigrator::class)->migrate(
        dryRun: false,
        afterAttachHook: fn () => throw new RuntimeException('boom'),
    ))->toThrow(RuntimeException::class);

    expect(app(LegacyPermissionMigrator::class)->permissionNamesFor($user->fresh()))->toBe($before);
});
