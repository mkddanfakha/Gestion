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

test('User hasPermission delegue a AuthorizationService', function () {
    $user = User::factory()->create(['role' => User::ROLE_USER]);
    $user->permissions()->sync(
        Permission::query()->where('name', 'products.view')->pluck('id'),
    );
    $user = $user->fresh();

    expect($user->hasPermission('products', 'view'))->toBeTrue();
    expect($user->hasPermission('products', 'delete'))->toBeFalse();
});

test('User hasPermissionByName delegue a AuthorizationService', function () {
    $user = User::factory()->create(['role' => User::ROLE_USER]);
    $user->permissions()->sync(
        Permission::query()->where('name', 'products.update')->pluck('id'),
    );
    $user = $user->fresh();

    expect($user->hasPermissionByName('products.edit'))->toBeTrue();
});

test('admin bypass via User hasPermission', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

    expect($admin->hasPermission('inventory', 'apply'))->toBeTrue();
    expect($admin->hasPermissionByName('backups.restore'))->toBeTrue();
});

test('Controller checkPermission retourne 403 sans permission', function () {
    $user = User::factory()->create(['role' => User::ROLE_USER]);
    $user->permissions()->sync(
        Permission::query()->where('name', 'products.view')->pluck('id'),
    );

    $this->actingAs($user)
        ->get(route('products.create'))
        ->assertForbidden();
});

test('Controller checkPermission autorise avec permission', function () {
    $user = User::factory()->create(['role' => User::ROLE_USER]);
    $user->permissions()->sync(
        Permission::query()->whereIn('name', ['products.view', 'products.create'])->pluck('id'),
    );

    $this->actingAs($user)
        ->get(route('products.create'))
        ->assertOk();
});

test('gestionnaire preset refuse sales via AuthorizationService', function () {
    $user = User::factory()->create(['role' => User::ROLE_GESTIONNAIRE]);
    $user->permissions()->sync(RolePresets::permissionIds(User::ROLE_GESTIONNAIRE));

    expect($this->authorization->allows($user, 'sales.view'))->toBeFalse();
    expect($this->authorization->allows($user, 'inventory.view'))->toBeTrue();
});

test('utilisateur personnalise conserve ses permissions sans modification automatique', function () {
    $user = User::factory()->create(['role' => User::ROLE_USER]);
    $ids = Permission::query()
        ->whereIn('name', ['products.view', 'sales.view'])
        ->pluck('id')
        ->all();

    $user->permissions()->sync($ids);
    $before = $user->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all();

    $this->authorization->allows($user, 'products.view');
    $user->refresh();

    expect($user->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all())->toBe($before);
});
