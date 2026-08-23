<?php

use App\Auth\AuthorizationService;
use App\Auth\LegacyPermissionMigrator;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->migrator = app(LegacyPermissionMigrator::class);
    $this->authorization = app(AuthorizationService::class);
});

function attachPermissionNames(User $user, array $names): void
{
    $ids = Permission::query()->whereIn('name', $names)->pluck('id')->all();
    $user->permissions()->syncWithoutDetaching($ids);
}

test('mapping products.edit vers products.update', function () {
    $mappings = PermissionCatalogLegacyMappings();
    expect($mappings['products.edit'] ?? null)->toBe('products.update');
});

test('mapping inventory.review vers inventory.reopen', function () {
    $mappings = PermissionCatalogLegacyMappings();
    expect($mappings['inventory.review'] ?? null)->toBe('inventory.reopen');
});

test('utilisateur avec edit et update conserve uniquement update', function () {
    $user = User::factory()->create(['role' => User::ROLE_USER]);
    attachPermissionNames($user, ['products.edit', 'products.update', 'products.view']);

    $this->migrator->migrate(dryRun: false);

    $names = $this->migrator->permissionNamesFor($user->fresh());
    expect($names)->toBe(['products.update', 'products.view'])
        ->and($names)->not->toContain('products.edit');
});

test('utilisateur custom conserve les autres permissions', function () {
    $user = User::factory()->create(['role' => User::ROLE_USER]);
    attachPermissionNames($user, ['products.edit', 'products.view', 'customers.view']);

    $before = $this->migrator->permissionNamesFor($user);
    $this->migrator->migrate(dryRun: false);
    $after = $this->migrator->permissionNamesFor($user->fresh());

    expect($after)->toContain('products.update')
        ->and($after)->toContain('products.view')
        ->and($after)->toContain('customers.view')
        ->and($after)->not->toContain('products.edit')
        ->and(count($after))->toBe(count($before)); // edit remplacé par update, même cardinalité
});

test('admin reste admin apres migration de pivots legacy', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
    ]);
    attachPermissionNames($admin, ['products.edit']);

    $this->migrator->migrate(dryRun: false);

    expect($admin->fresh()->role)->toBe(User::ROLE_ADMIN)
        ->and($admin->fresh()->isAdmin())->toBeTrue()
        ->and($this->migrator->permissionNamesFor($admin->fresh()))->toBe(['products.update']);
});

test('aucun pivot legacy apres migration', function () {
    $user = User::factory()->create(['role' => User::ROLE_USER]);
    attachPermissionNames($user, ['sales.edit', 'inventory.review']);

    $this->migrator->migrate(dryRun: false);

    expect($this->migrator->status()['legacy_assignment_total'])->toBe(0)
        ->and($this->migrator->permissionNamesFor($user->fresh()))
        ->toEqualCanonicalizing(['sales.update', 'inventory.reopen']);
});

test('dry-run ne modifie aucune donnee', function () {
    $user = User::factory()->create(['role' => User::ROLE_USER]);
    attachPermissionNames($user, ['quotes.edit']);
    $before = $this->migrator->permissionNamesFor($user);

    $result = $this->migrator->migrate(dryRun: true);

    expect($result['dry_run'])->toBeTrue()
        ->and($this->migrator->permissionNamesFor($user->fresh()))->toBe($before)
        ->and($this->migrator->status()['legacy_assignments']['quotes.edit'])->toBe(1);
});

test('migration est idempotente', function () {
    $user = User::factory()->create(['role' => User::ROLE_USER]);
    attachPermissionNames($user, ['expenses.edit', 'expenses.view']);

    $first = $this->migrator->migrate(dryRun: false);
    $second = $this->migrator->migrate(dryRun: false);

    expect($first['legacy_removed'])->toBeGreaterThan(0)
        ->and($second['nothing_to_do'] ?? ($second['legacy_removed'] === 0))->toBeTruthy()
        ->and($second['legacy_removed'])->toBe(0)
        ->and($second['canonical_added'])->toBe(0)
        ->and($this->migrator->permissionNamesFor($user->fresh()))
        ->toEqualCanonicalizing(['expenses.update', 'expenses.view']);
});

test('erreur pendant migration annule la transaction', function () {
    $user = User::factory()->create(['role' => User::ROLE_USER]);
    attachPermissionNames($user, ['company.edit']);
    $before = $this->migrator->permissionNamesFor($user);

    expect(fn () => $this->migrator->migrate(
        dryRun: false,
        afterAttachHook: function (): void {
            throw new RuntimeException('boom');
        },
    ))->toThrow(RuntimeException::class);

    expect($this->migrator->permissionNamesFor($user->fresh()))->toBe($before)
        ->and(DB::table('user_permissions')->count())->toBe(1);
});

test('inventory.review migre vers reopen et AuthorizationService autorise', function () {
    $user = User::factory()->create(['role' => User::ROLE_USER]);
    attachPermissionNames($user, ['inventory.review']);

    $this->migrator->migrate(dryRun: false);
    $user = $user->fresh();
    $this->authorization->forgetCachedPermissions($user);

    expect($this->authorization->allows($user, 'inventory.reopen'))->toBeTrue()
        ->and($this->authorization->allows($user, 'inventory.review'))->toBeTrue(); // alias lecture encore OK
});

function PermissionCatalogLegacyMappings(): array
{
    return \App\Auth\PermissionCatalog::legacyMappings();
}
