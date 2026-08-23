<?php

use App\Auth\RolePresets;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

test('creating vendeur assigns role preset including dashboard view', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'Vendeur Test',
            'email' => 'vendeur.test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => User::ROLE_VENDEUR,
            'is_active' => true,
        ])
        ->assertRedirect(route('admin.users.index'));

    $user = User::query()->where('email', 'vendeur.test@example.com')->firstOrFail();
    $names = $user->permissions()->pluck('name')->sort()->values()->all();

    expect($names)->toContain('dashboard.view');
    expect($names)->toContain('sales.view');
    expect($names)->toContain('products.view');
    expect($names)->toEqualCanonicalizing(RolePresets::permissionNames(User::ROLE_VENDEUR));
});

test('creating gestionnaire assigns preset without sales permissions', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'Gestionnaire Test',
            'email' => 'gestionnaire.test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => User::ROLE_GESTIONNAIRE,
            'is_active' => true,
        ])
        ->assertRedirect(route('admin.users.index'));

    $user = User::query()->where('email', 'gestionnaire.test@example.com')->firstOrFail();
    $names = $user->permissions()->pluck('name')->all();

    expect($names)->toContain('inventory.view');
    expect(collect($names)->contains(fn (string $name): bool => str_starts_with($name, 'sales.')))->toBeFalse();
    expect($names)->toEqualCanonicalizing(RolePresets::permissionNames(User::ROLE_GESTIONNAIRE));
});

test('creating user role keeps custom permissions from request', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $customIds = Permission::query()
        ->whereIn('name', ['products.view', 'customers.view'])
        ->pluck('id')
        ->all();

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'User Custom',
            'email' => 'user.custom@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => User::ROLE_USER,
            'is_active' => true,
            'permissions' => $customIds,
        ])
        ->assertRedirect(route('admin.users.index'));

    $user = User::query()->where('email', 'user.custom@example.com')->firstOrFail();

    expect($user->permissions()->pluck('name')->sort()->values()->all())
        ->toEqual(['customers.view', 'products.view']);
});

test('role presets definition alone does not resync existing users', function () {
    $user = User::factory()->create(['role' => User::ROLE_USER]);
    $permissionIds = Permission::query()
        ->whereIn('name', ['products.view', 'sales.view'])
        ->pluck('id')
        ->all();

    $user->permissions()->sync($permissionIds);
    $before = $user->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all();

    RolePresets::validate();
    RolePresets::permissionNames(User::ROLE_GESTIONNAIRE);
    RolePresets::permissionNames(User::ROLE_VENDEUR);

    $user->refresh();

    expect($user->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all())->toBe($before);
});

test('updating user profile without role change preserves custom user permissions', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $user = User::factory()->create(['role' => User::ROLE_USER]);
    $permissionIds = Permission::query()
        ->whereIn('name', ['products.view', 'customers.view'])
        ->pluck('id')
        ->all();

    $user->permissions()->sync($permissionIds);

    $this->actingAs($admin)
        ->put(route('admin.users.update', $user), [
            'name' => 'User Renamed',
            'email' => $user->email,
            'role' => User::ROLE_USER,
            'is_active' => true,
            'permissions' => $permissionIds,
        ])
        ->assertRedirect(route('admin.users.index'));

    $user->refresh();

    expect($user->name)->toBe('User Renamed');
    expect($user->permissions()->pluck('name')->sort()->values()->all())
        ->toEqual(['customers.view', 'products.view']);
});
