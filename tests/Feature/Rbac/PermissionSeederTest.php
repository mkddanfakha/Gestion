<?php

use App\Auth\PermissionCatalog;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('permission seeder crée toutes les permissions du catalogue sans doublon', function () {
    $this->seed(PermissionSeeder::class);

    expect(Permission::query()->count())->toBe(count(PermissionCatalog::all()));
    expect(Permission::query()->pluck('name')->unique()->count())->toBe(count(PermissionCatalog::all()));
});

test('permission seeder est idempotent', function () {
    $this->seed(PermissionSeeder::class);
    $firstCount = Permission::query()->count();
    $firstNames = Permission::query()->orderBy('name')->pluck('name')->all();

    $this->seed(PermissionSeeder::class);

    expect(Permission::query()->count())->toBe($firstCount);
    expect(Permission::query()->orderBy('name')->pluck('name')->all())->toBe($firstNames);
});

test('permission seeder conserve les permissions legacy en base', function () {
    $this->seed(PermissionSeeder::class);

    expect(Permission::query()->where('name', 'products.edit')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'products.update')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'inventory.review')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'inventory.reopen')->exists())->toBeTrue();
});

test('permission seeder ne modifie pas user_permissions existants', function () {
    $this->seed(PermissionSeeder::class);

    $user = User::factory()->create(['role' => User::ROLE_USER]);
    $permissionIds = Permission::query()
        ->whereIn('name', ['products.view', 'products.edit', 'inventory.review'])
        ->pluck('id')
        ->all();

    $user->permissions()->sync($permissionIds);
    $before = $user->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all();

    $this->seed(PermissionSeeder::class);
    $this->seed(PermissionSeeder::class);

    $user->refresh();

    expect($user->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all())->toBe($before);
});
