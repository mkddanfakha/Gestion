<?php

use App\Auth\LegacyPermissionMigrator;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

test('commande dry-run n applique aucun changement', function () {
    $user = User::factory()->create(['role' => User::ROLE_USER]);
    $user->permissions()->sync(
        Permission::query()->whereIn('name', ['products.edit', 'inventory.review'])->pluck('id'),
    );

    $this->artisan('rbac:migrate-legacy-permissions', ['--dry-run' => true])
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('products.edit → products.update')
        ->expectsOutputToContain('inventory.review → inventory.reopen')
        ->expectsOutputToContain('No database changes performed.')
        ->assertSuccessful();

    expect(app(LegacyPermissionMigrator::class)->status()['legacy_assignment_total'])->toBe(2);
});

test('commande migrate les pivots et est idempotente', function () {
    $user = User::factory()->create(['role' => User::ROLE_USER]);
    $user->permissions()->sync(
        Permission::query()->whereIn('name', ['products.edit', 'customers.view'])->pluck('id'),
    );

    $this->artisan('rbac:migrate-legacy-permissions', ['--force' => true])
        ->expectsOutputToContain('Migration completed.')
        ->assertSuccessful();

    $migrator = app(LegacyPermissionMigrator::class);
    expect($migrator->status()['legacy_assignment_total'])->toBe(0)
        ->and($migrator->permissionNamesFor($user->fresh()))
        ->toEqualCanonicalizing(['products.update', 'customers.view']);

    expect(ActivityLog::query()
        ->where('action', ActivityLog::ACTION_RBAC_LEGACY_PERMISSIONS_MIGRATED)
        ->exists())->toBeTrue();

    $this->artisan('rbac:migrate-legacy-permissions', ['--force' => true])
        ->expectsOutputToContain('Nothing to migrate.')
        ->assertSuccessful();
});

test('commande status affiche aucun pivot legacy', function () {
    $this->artisan('rbac:migrate-legacy-permissions', ['--status' => true])
        ->expectsOutputToContain('NO LEGACY USER ASSIGNMENTS')
        ->assertSuccessful();
});
