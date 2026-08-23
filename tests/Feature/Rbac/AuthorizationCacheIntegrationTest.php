<?php

use App\Auth\AuthorizationService;
use App\Auth\RolePresets;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->authorization = app(AuthorizationService::class);
});

function cacheFeatureAdmin(): User
{
    return User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

function cacheFeatureVendeur(): User
{
    $user = User::factory()->create([
        'role' => User::ROLE_VENDEUR,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->permissions()->sync(RolePresets::permissionIds(User::ROLE_VENDEUR));

    return $user->fresh();
}

test('vendeur plusieurs permissions coherentes dans une meme evaluation', function () {
    $vendeur = cacheFeatureVendeur();

    expect($this->authorization->allows($vendeur, 'products.view'))->toBeTrue()
        ->and($this->authorization->allows($vendeur, 'sales.create'))->toBeTrue()
        ->and($this->authorization->allows($vendeur, 'dashboard.view'))->toBeTrue()
        ->and($this->authorization->allows($vendeur, 'inventory.apply'))->toBeFalse()
        ->and($this->authorization->any($vendeur, ['sales.view', 'inventory.apply']))->toBeTrue()
        ->and($this->authorization->all($vendeur, ['sales.view', 'inventory.apply']))->toBeFalse();
});

test('modification des permissions via admin est immediate', function () {
    $admin = cacheFeatureAdmin();
    $user = User::factory()->create([
        'role' => User::ROLE_USER,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $permId = Permission::query()->where('name', 'products.update')->value('id');

    $this->actingAs($admin)
        ->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => User::ROLE_USER,
            'is_active' => true,
            'permissions' => [$permId],
        ])
        ->assertRedirect(route('admin.users.index'));

    $fresh = $user->fresh();
    expect($this->authorization->allows($fresh, 'products.update'))->toBeTrue()
        ->and($this->authorization->allows($fresh, 'products.view'))->toBeFalse();
});

test('changement de role applique le nouveau preset immediatement', function () {
    $admin = cacheFeatureAdmin();
    $user = cacheFeatureVendeur();

    $this->actingAs($admin)
        ->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => User::ROLE_GESTIONNAIRE,
            'is_active' => true,
        ])
        ->assertRedirect(route('admin.users.index'));

    $fresh = $user->fresh();
    expect($fresh->role)->toBe(User::ROLE_GESTIONNAIRE)
        ->and($this->authorization->allows($fresh, 'inventory.view'))->toBeTrue()
        ->and($this->authorization->allows($fresh, 'sales.create'))->toBeFalse()
        ->and($this->authorization->allows($fresh, 'sales.view'))->toBeFalse();
});

test('retrait de permission est immediat', function () {
    $admin = cacheFeatureAdmin();
    $user = User::factory()->create([
        'role' => User::ROLE_USER,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $viewId = Permission::query()->where('name', 'products.view')->value('id');
    $updateId = Permission::query()->where('name', 'products.update')->value('id');
    $user->permissions()->sync([$viewId, $updateId]);

    expect($this->authorization->allows($user->fresh(), 'products.update'))->toBeTrue();

    $this->actingAs($admin)
        ->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => User::ROLE_USER,
            'is_active' => true,
            'permissions' => [$viewId],
        ])
        ->assertRedirect();

    expect($this->authorization->allows($user->fresh(), 'products.update'))->toBeFalse()
        ->and($this->authorization->allows($user->fresh(), 'products.view'))->toBeTrue();
});

test('ajout de permission est immediat', function () {
    $admin = cacheFeatureAdmin();
    $user = User::factory()->create([
        'role' => User::ROLE_USER,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $viewId = Permission::query()->where('name', 'products.view')->value('id');
    $updateId = Permission::query()->where('name', 'products.update')->value('id');
    $user->permissions()->sync([$viewId]);

    $this->actingAs($admin)
        ->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => User::ROLE_USER,
            'is_active' => true,
            'permissions' => [$viewId, $updateId],
        ])
        ->assertRedirect();

    expect($this->authorization->allows($user->fresh(), 'products.update'))->toBeTrue();
});

test('admin conserve le bypass', function () {
    $admin = cacheFeatureAdmin();

    expect($this->authorization->allows($admin, 'products.delete'))->toBeTrue()
        ->and($this->authorization->forUser($admin))->toBe([]);
});

test('dernier admin ne peut toujours pas etre rétrogradé', function () {
    $admin = cacheFeatureAdmin();

    $this->actingAs($admin)
        ->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => User::ROLE_VENDEUR,
            'is_active' => true,
        ])
        ->assertForbidden();

    expect($admin->fresh()->role)->toBe(User::ROLE_ADMIN)
        ->and($this->authorization->allows($admin->fresh(), 'anything'))->toBeTrue();
});

test('admin rétrogradé perd le bypass immediatement', function () {
    $actor = cacheFeatureAdmin();
    $target = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($actor)
        ->put(route('admin.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'role' => User::ROLE_VENDEUR,
            'is_active' => true,
        ])
        ->assertRedirect();

    $fresh = $target->fresh();
    expect($fresh->isAdmin())->toBeFalse()
        ->and($this->authorization->allows($fresh, 'inventory.apply'))->toBeFalse()
        ->and($this->authorization->allows($fresh, 'sales.create'))->toBeTrue();
});
