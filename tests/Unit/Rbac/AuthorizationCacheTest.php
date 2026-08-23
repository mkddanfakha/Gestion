<?php

use App\Auth\AuthorizationService;
use App\Auth\RolePresets;
use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->authorization = app(AuthorizationService::class);
});

function cachePermissionIds(array $names): array
{
    return Permission::query()->whereIn('name', $names)->pluck('id')->all();
}

function cacheUserWithPermissions(array $names, string $role = User::ROLE_USER): User
{
    $user = User::factory()->create(['role' => $role, 'is_active' => true]);
    $user->permissions()->sync(cachePermissionIds($names));

    return $user->fresh();
}

function countPermissionQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $log = DB::getQueryLog();
    DB::disableQueryLog();
    DB::flushQueryLog();

    return count(array_filter(
        $log,
        fn (array $query): bool => str_contains(strtolower($query['query']), 'permission')
    ));
}

test('plusieurs allows chargent les permissions une seule fois', function () {
    $user = cacheUserWithPermissions(['products.view', 'sales.create', 'customers.view']);

    $queries = countPermissionQueries(function () use ($user) {
        $this->authorization->allows($user, 'products.view');
        $this->authorization->allows($user, 'sales.create');
        $this->authorization->allows($user, 'customers.view');
        $this->authorization->allows($user, 'products.update');
    });

    expect($queries)->toBe(1)
        ->and($user->relationLoaded('permissions'))->toBeTrue();
});

test('any ne relance pas de requetes permissions inutiles', function () {
    $user = cacheUserWithPermissions(['products.view']);

    $queries = countPermissionQueries(function () use ($user) {
        $this->authorization->any($user, ['products.delete', 'products.create', 'products.view']);
    });

    expect($queries)->toBe(1);
});

test('all ne relance pas de requetes permissions inutiles', function () {
    $user = cacheUserWithPermissions(['products.view', 'products.update', 'sales.create']);

    $queries = countPermissionQueries(function () use ($user) {
        $this->authorization->all($user, ['products.view', 'products.update', 'sales.create']);
    });

    expect($queries)->toBe(1);
});

test('admin bypass ne requete pas les permissions', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

    $queries = countPermissionQueries(function () use ($admin) {
        $this->authorization->allows($admin, 'products.view');
        $this->authorization->allows($admin, 'sales.create');
        $this->authorization->all($admin, ['a', 'b', 'c']);
    });

    expect($queries)->toBe(0);
});

test('legacy edit update reste fonctionnel avec cache', function () {
    $user = cacheUserWithPermissions(['products.update']);

    expect($this->authorization->allows($user, 'products.edit'))->toBeTrue()
        ->and($this->authorization->allows($user, 'products.update'))->toBeTrue();
});

test('inventory review reopen reste fonctionnel avec cache', function () {
    $user = cacheUserWithPermissions(['inventory.review']);

    expect($this->authorization->allows($user, 'inventory.reopen'))->toBeTrue()
        ->and($this->authorization->allows($user, PermissionName::InventoryReopen))->toBeTrue();
});

test('permissions deja eager-loaded aucune requete supplementaire', function () {
    $user = cacheUserWithPermissions(['products.view', 'sales.view']);
    $user->load('permissions');

    $queries = countPermissionQueries(function () use ($user) {
        $this->authorization->allows($user, 'products.view');
        $this->authorization->allows($user, 'sales.view');
        $this->authorization->allows($user, 'products.delete');
    });

    expect($queries)->toBe(0);
});

test('utilisateur sans permission retourne false', function () {
    $user = cacheUserWithPermissions(['products.view']);

    expect($this->authorization->allows($user, 'products.delete'))->toBeFalse();
});

test('permission ajoutee apres invalidation devient true', function () {
    $user = cacheUserWithPermissions(['products.view']);

    expect($this->authorization->allows($user, 'products.update'))->toBeFalse();

    $user->permissions()->sync(cachePermissionIds(['products.view', 'products.update']));
    $this->authorization->forgetCachedPermissions($user);

    expect($this->authorization->allows($user, 'products.update'))->toBeTrue();
});

test('permission retiree apres invalidation devient false', function () {
    $user = cacheUserWithPermissions(['products.view', 'products.update']);

    expect($this->authorization->allows($user, 'products.update'))->toBeTrue();

    $user->permissions()->sync(cachePermissionIds(['products.view']));
    $this->authorization->forgetCachedPermissions($user);

    expect($this->authorization->allows($user, 'products.update'))->toBeFalse();
});

test('sans invalidation apres sync le cache relation peut etre stale — forget est obligatoire', function () {
    $user = cacheUserWithPermissions(['products.update']);
    expect($this->authorization->allows($user, 'products.update'))->toBeTrue();

    $user->permissions()->sync(cachePermissionIds(['products.view']));
    // Relation encore chargée avec l'ancien set si non invalidée
    expect($user->relationLoaded('permissions'))->toBeTrue();
    expect($this->authorization->allows($user, 'products.update'))->toBeTrue(); // stale

    $this->authorization->forgetCachedPermissions($user);
    expect($this->authorization->allows($user, 'products.update'))->toBeFalse();
});
