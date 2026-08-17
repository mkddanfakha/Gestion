<?php

use App\Models\Permission;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedSupplierPermissions(): void
{
    foreach (['view', 'create', 'edit', 'update', 'delete', 'export'] as $action) {
        Permission::firstOrCreate(
            [
                'resource' => 'suppliers',
                'action' => $action,
            ],
            [
                'name' => Permission::generateName('suppliers', $action),
                'description' => "suppliers.{$action}",
            ],
        );
    }

    foreach (['view', 'create', 'edit', 'update', 'delete', 'print', 'download'] as $action) {
        Permission::firstOrCreate(
            [
                'resource' => 'purchase-orders',
                'action' => $action,
            ],
            [
                'name' => Permission::generateName('purchase-orders', $action),
                'description' => "purchase-orders.{$action}",
            ],
        );
    }
}

test('supplier autocomplete returns active suppliers matching search query', function () {
    seedSupplierPermissions();

    $admin = User::factory()->create(['role' => 'admin']);

    $matching = Supplier::create([
        'name' => 'SONATEL',
        'email' => 'contact@sonatel.sn',
        'phone' => '771234567',
        'status' => 'active',
    ]);

    Supplier::create([
        'name' => 'Autre Fournisseur',
        'status' => 'active',
    ]);

    Supplier::create([
        'name' => 'SONATEL Inactif',
        'status' => 'inactive',
    ]);

    $response = $this->actingAs($admin)->getJson(route('suppliers.autocomplete', [
        'q' => 'sona',
    ]));

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment([
            'id' => $matching->id,
            'name' => 'SONATEL',
            'email' => 'contact@sonatel.sn',
            'phone' => '771234567',
        ]);
});

test('supplier autocomplete is available to users with purchase order create permission', function () {
    seedSupplierPermissions();

    $user = User::factory()->create(['role' => 'gestionnaire']);
    $permission = Permission::where('name', Permission::generateName('purchase-orders', 'create'))->first();
    $user->permissions()->attach($permission);

    Supplier::create([
        'name' => 'Fournisseur BC',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->getJson(route('suppliers.autocomplete', [
        'q' => 'fournisseur',
    ]));

    $response->assertOk()
        ->assertJsonCount(1);
});

test('supplier autocomplete rejects unauthenticated requests', function () {
    $response = $this->getJson(route('suppliers.autocomplete', ['q' => 'test']));

    $response->assertUnauthorized();
});

test('supplier autocomplete rejects users without relevant permissions', function () {
    seedSupplierPermissions();

    $user = User::factory()->create(['role' => 'vendeur']);

    $response = $this->actingAs($user)->getJson(route('suppliers.autocomplete', ['q' => 'test']));

    $response->assertForbidden();
});

test('supplier quick create via json stores supplier and returns payload', function () {
    seedSupplierPermissions();

    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('suppliers.store'), [
        'name' => 'Nouveau Fournisseur',
        'contact_person' => 'Amadou Diop',
        'phone' => '771112233',
        'email' => 'amadou@example.com',
        'address' => 'Dakar, Sénégal',
        'status' => 'active',
    ]);

    $response->assertCreated()
        ->assertJson([
            'name' => 'Nouveau Fournisseur',
            'contact_person' => 'Amadou Diop',
            'phone' => '771112233',
            'email' => 'amadou@example.com',
            'address' => 'Dakar, Sénégal',
            'status' => 'active',
        ])
        ->assertJsonStructure([
            'id',
            'name',
            'contact_person',
            'email',
            'phone',
            'mobile',
            'address',
            'city',
            'country',
            'status',
        ]);

    $supplier = Supplier::query()->where('name', 'Nouveau Fournisseur')->first();

    expect($supplier)->not->toBeNull()
        ->and($supplier->email)->toBe('amadou@example.com')
        ->and($supplier->status)->toBe('active');
});

test('supplier quick create validates required fields', function () {
    seedSupplierPermissions();

    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('suppliers.store'), [
        'name' => '',
        'status' => 'active',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('supplier quick create rejects unauthorized users', function () {
    seedSupplierPermissions();

    $user = User::factory()->create(['role' => 'vendeur']);

    $response = $this->actingAs($user)->postJson(route('suppliers.store'), [
        'name' => 'Fournisseur Interdit',
        'status' => 'active',
    ]);

    $response->assertForbidden();

    expect(Supplier::query()->where('name', 'Fournisseur Interdit')->exists())->toBeFalse();
});

test('supplier classic create still redirects to index for non json requests', function () {
    seedSupplierPermissions();

    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->post(route('suppliers.store'), [
        'name' => 'Fournisseur Classique',
        'status' => 'active',
    ]);

    $response->assertRedirect(route('suppliers.index'));

    expect(Supplier::query()->where('name', 'Fournisseur Classique')->exists())->toBeTrue();
});
