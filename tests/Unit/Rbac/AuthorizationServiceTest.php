<?php

use App\Auth\AuthorizationService;
use App\Auth\RolePresets;
use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->authorization = app(AuthorizationService::class);
});

function authPermissionIds(array $names): array
{
    return Permission::query()->whereIn('name', $names)->pluck('id')->all();
}

function authUserWithPermissions(array $names, string $role = User::ROLE_USER): User
{
    $user = User::factory()->create(['role' => $role]);
    $user->permissions()->sync(authPermissionIds($names));

    return $user->fresh();
}

test('admin est autorise pour toute permission', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

    expect($this->authorization->allows($admin, 'products.view'))->toBeTrue();
    expect($this->authorization->allows($admin, PermissionName::InventoryApply))->toBeTrue();
    expect($this->authorization->allows($admin, 'unknown.permission'))->toBeTrue();
});

test('utilisateur avec permission est autorise', function () {
    $user = authUserWithPermissions(['products.view']);

    expect($this->authorization->allows($user, 'products.view'))->toBeTrue();
    expect($this->authorization->denies($user, 'products.view'))->toBeFalse();
});

test('utilisateur sans permission est refuse', function () {
    $user = authUserWithPermissions(['products.view']);

    expect($this->authorization->allows($user, 'products.delete'))->toBeFalse();
});

test('permission inconnue est refusee pour non admin', function () {
    $user = authUserWithPermissions(['products.view']);

    expect($this->authorization->allows($user, 'unknown.permission'))->toBeFalse();
});

test('PermissionName est accepte', function () {
    $user = authUserWithPermissions(['sales.view']);

    expect($this->authorization->allows($user, PermissionName::SalesView))->toBeTrue();
});

test('permission string est acceptee', function () {
    $user = authUserWithPermissions(['sales.view']);

    expect($this->authorization->allows($user, 'sales.view'))->toBeTrue();
});

test('any retourne true si au moins une permission est autorisee', function () {
    $user = authUserWithPermissions(['products.view']);

    expect($this->authorization->any($user, ['products.view', 'products.delete']))->toBeTrue();
    expect($this->authorization->any($user, ['products.delete', 'products.create']))->toBeFalse();
});

test('all retourne true uniquement si toutes les permissions sont autorisees', function () {
    $user = authUserWithPermissions(['products.view', 'products.update']);

    expect($this->authorization->all($user, ['products.view', 'products.update']))->toBeTrue();
    expect($this->authorization->all($user, ['products.view', 'products.delete']))->toBeFalse();
    expect($this->authorization->all($user, []))->toBeFalse();
});

test('permission legacy edit reconnue via update en base', function () {
    $user = authUserWithPermissions(['products.update']);

    expect($this->authorization->allows($user, 'products.edit'))->toBeTrue();
});

test('permission canonique update reconnue via edit legacy en base', function () {
    $user = authUserWithPermissions(['products.edit']);

    expect($this->authorization->allows($user, 'products.update'))->toBeTrue();
});

test('inventory review legacy autorise aussi inventory.reopen', function () {
    $user = authUserWithPermissions(['inventory.review']);

    expect($this->authorization->allows($user, 'inventory.review'))->toBeTrue();
    expect(Permission::query()->where('name', 'inventory.reopen')->exists())->toBeTrue();
    expect($this->authorization->allows($user, 'inventory.reopen'))->toBeTrue();
});

test('inventory reopen canonique autorise aussi inventory.review', function () {
    $user = authUserWithPermissions(['inventory.reopen']);

    expect($this->authorization->allows($user, 'inventory.reopen'))->toBeTrue();
    expect($this->authorization->allows($user, 'inventory.review'))->toBeTrue();
});

test('utilisateur custom conserve ses permissions', function () {
    $user = authUserWithPermissions(['products.view', 'customers.view']);

    expect($this->authorization->forUser($user))->toEqualCanonicalizing(['customers.view', 'products.view']);
});

test('vendeur conforme au role presets', function () {
    $user = User::factory()->create(['role' => User::ROLE_VENDEUR]);
    $user->permissions()->sync(RolePresets::permissionIds(User::ROLE_VENDEUR));
    $user = $user->fresh();

    foreach (RolePresets::permissionNames(User::ROLE_VENDEUR) as $permission) {
        expect($this->authorization->allows($user, $permission))->toBeTrue();
    }
});

test('gestionnaire cannot sell', function () {
    $user = User::factory()->create(['role' => User::ROLE_GESTIONNAIRE]);
    $user->permissions()->sync(RolePresets::permissionIds(User::ROLE_GESTIONNAIRE));
    $user = $user->fresh();

    expect($this->authorization->allows($user, 'sales.view'))->toBeFalse();
    expect($this->authorization->allows($user, 'sales.create'))->toBeFalse();
    expect($this->authorization->allows($user, 'sales.update'))->toBeFalse();
    expect($this->authorization->allows($user, 'sales.delete'))->toBeFalse();
});

test('gestionnaire has inventory view', function () {
    $user = User::factory()->create(['role' => User::ROLE_GESTIONNAIRE]);
    $user->permissions()->sync(RolePresets::permissionIds(User::ROLE_GESTIONNAIRE));
    $user = $user->fresh();

    expect($this->authorization->allows($user, 'inventory.view'))->toBeTrue();
});

test('forUser admin retourne tableau vide', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

    expect($this->authorization->forUser($admin))->toBe([]);
});

test('allows ne modifie pas le pivot user_permissions', function () {
    $user = authUserWithPermissions(['products.view']);
    $before = $user->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all();

    $this->authorization->allows($user, 'products.view');
    $this->authorization->allows($user, 'products.delete');
    $this->authorization->forUser($user);

    $user->refresh();

    expect($user->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all())->toBe($before);
});
