<?php

use App\Auth\PermissionCatalog;
use App\Auth\PermissionCatalogSynchronizer;
use App\Database\DatabaseSafetyGuard;
use App\Database\ProtectedDatabaseException;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function syncConfirmPhrase(): string
{
    $database = DatabaseSafetyGuard::resolveDatabaseName();

    return PermissionCatalogSynchronizer::confirmationPhraseForDatabase($database);
}

function seedOnlyUserActivitiesView(): void
{
    Permission::query()->where('name', '!=', 'user-activities.view')->delete();

    $definition = PermissionCatalog::findByName('user-activities.view');
    expect($definition)->not->toBeNull();

    $permission = Permission::query()->firstOrCreate(
        [
            'resource' => $definition['module'],
            'action' => $definition['action'],
        ],
        [
            'name' => $definition['name'],
            'description' => 'custom-description-should-remain',
        ],
    );

    // Forcer une description custom pour le test « existante non modifiée ».
    if ($permission->description !== 'custom-description-should-remain') {
        $permission->forceFill(['description' => 'custom-description-should-remain'])->save();
    }

    expect(Permission::query()->count())->toBe(1)
        ->and(Permission::query()->where('name', 'user-activities.view')->exists())->toBeTrue();
}

test('status est strictement lecture seule', function () {
    seedOnlyUserActivitiesView();
    $before = Permission::query()->orderBy('id')->get()->toArray();

    $this->artisan('rbac:sync-permission-catalog', ['--status' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('RBAC PERMISSION CATALOG STATUS')
        ->expectsOutputToContain('Catalog permissions : '.count(PermissionCatalog::all()))
        ->expectsOutputToContain('Existing permissions: 1')
        ->expectsOutputToContain('Missing permissions  : '.(count(PermissionCatalog::all()) - 1));

    expect(Permission::query()->orderBy('id')->get()->toArray())->toBe($before);
});

test('dry-run ne cree aucune permission', function () {
    seedOnlyUserActivitiesView();
    $beforeCount = Permission::query()->count();

    $this->artisan('rbac:sync-permission-catalog', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('RBAC PERMISSION CATALOG DRY RUN')
        ->expectsOutputToContain('No changes performed.');

    expect(Permission::query()->count())->toBe($beforeCount);
});

test('analyse detecte les permissions manquantes par rapport au catalogue', function () {
    seedOnlyUserActivitiesView();

    $analysis = app(PermissionCatalogSynchronizer::class)->analyze();
    $catalogNames = collect(PermissionCatalog::all())->pluck('name')->sort()->values()->all();
    $expectedMissing = array_values(array_diff($catalogNames, ['user-activities.view']));

    expect($analysis['catalog_count'])->toBe(count($catalogNames))
        ->and($analysis['existing_count'])->toBe(1)
        ->and($analysis['missing_count'])->toBe(count($expectedMissing))
        ->and($analysis['missing_names'])->toBe($expectedMissing)
        ->and($analysis['existing_names'])->toBe(['user-activities.view']);
});

test('execution reelle sans confirm est refusee', function () {
    seedOnlyUserActivitiesView();
    $before = Permission::query()->count();

    $this->artisan('rbac:sync-permission-catalog')
        ->assertFailed()
        ->expectsOutputToContain('Confirmation manquante ou incorrecte');

    expect(Permission::query()->count())->toBe($before);
});

test('mauvaise confirmation est refusee', function () {
    seedOnlyUserActivitiesView();
    $before = Permission::query()->count();

    $this->artisan('rbac:sync-permission-catalog', ['--confirm' => 'YES'])
        ->assertFailed()
        ->expectsOutputToContain('Confirmation manquante ou incorrecte');

    expect(Permission::query()->count())->toBe($before);
});

test('confirmation correcte cree toutes les permissions absentes', function () {
    seedOnlyUserActivitiesView();
    $catalogCount = count(PermissionCatalog::all());

    $this->artisan('rbac:sync-permission-catalog', [
        '--confirm' => syncConfirmPhrase(),
    ])->assertSuccessful()
        ->expectsOutputToContain('SYNC COMPLETED')
        ->expectsOutputToContain('Created             : '.($catalogCount - 1));

    expect(Permission::query()->count())->toBe($catalogCount);

    $dbNames = Permission::query()->orderBy('name')->pluck('name')->all();
    $catalogNames = collect(PermissionCatalog::all())->pluck('name')->sort()->values()->all();

    expect($dbNames)->toBe($catalogNames);
});

test('synchronisation est idempotente', function () {
    seedOnlyUserActivitiesView();
    $confirm = syncConfirmPhrase();

    $this->artisan('rbac:sync-permission-catalog', ['--confirm' => $confirm])->assertSuccessful();
    $afterFirst = Permission::query()->count();

    $this->artisan('rbac:sync-permission-catalog', ['--confirm' => $confirm])
        ->assertSuccessful()
        ->expectsOutputToContain('Created             : 0');

    expect(Permission::query()->count())->toBe($afterFirst)
        ->and(app(PermissionCatalogSynchronizer::class)->analyze()['missing_count'])->toBe(0);
});

test('user_permissions reste inchange apres sync', function () {
    seedOnlyUserActivitiesView();

    $user = User::factory()->create(['role' => User::ROLE_USER]);
    $permissionId = Permission::query()->where('name', 'user-activities.view')->value('id');
    $user->permissions()->sync([(int) $permissionId]);

    $pivotBefore = DB::table('user_permissions')
        ->where('user_id', $user->id)
        ->orderBy('permission_id')
        ->get(['user_id', 'permission_id'])
        ->map(fn ($row) => [(int) $row->user_id, (int) $row->permission_id])
        ->all();

    $this->artisan('rbac:sync-permission-catalog', [
        '--confirm' => syncConfirmPhrase(),
    ])->assertSuccessful();

    $pivotAfter = DB::table('user_permissions')
        ->where('user_id', $user->id)
        ->orderBy('permission_id')
        ->get(['user_id', 'permission_id'])
        ->map(fn ($row) => [(int) $row->user_id, (int) $row->permission_id])
        ->all();

    expect($pivotAfter)->toBe($pivotBefore)
        ->and(Permission::query()->count())->toBe(count(PermissionCatalog::all()));
});

test('aucune suppression des permissions orphelines', function () {
    seedOnlyUserActivitiesView();

    Permission::query()->create([
        'resource' => 'custom-orphan',
        'action' => 'view',
        'name' => 'custom-orphan.view',
        'description' => 'orphan row',
    ]);

    $this->artisan('rbac:sync-permission-catalog', [
        '--confirm' => syncConfirmPhrase(),
    ])->assertSuccessful();

    expect(Permission::query()->where('name', 'custom-orphan.view')->exists())->toBeTrue()
        ->and(app(PermissionCatalogSynchronizer::class)->analyze()['orphan_names'])
        ->toContain('custom-orphan.view');
});

test('permission existante n est pas modifiee', function () {
    seedOnlyUserActivitiesView();
    $existing = Permission::query()->where('name', 'user-activities.view')->firstOrFail();
    $original = $existing->only(['id', 'name', 'resource', 'action', 'description', 'created_at', 'updated_at']);

    $this->artisan('rbac:sync-permission-catalog', [
        '--confirm' => syncConfirmPhrase(),
    ])->assertSuccessful();

    $existing->refresh();

    expect($existing->only(['id', 'name', 'resource', 'action', 'description']))
        ->toBe(collect($original)->only(['id', 'name', 'resource', 'action', 'description'])->all())
        ->and($existing->description)->toBe('custom-description-should-remain');
});

test('DatabaseSafetyGuard continue de bloquer db:seed sur base protegee', function () {
    Config::set('database-safety.protected_databases', ['gestion']);
    Config::set('database.connections.mysql.database', 'gestion');
    Config::set('app.env', 'production');

    expect(fn () => DatabaseSafetyGuard::assertDestructiveOperationAllowed('db:seed', 'mysql'))
        ->toThrow(ProtectedDatabaseException::class);

    // Restaure le contexte de test sqlite
    Config::set('app.env', 'testing');
});

test('PermissionSeeder conserve le meme comportement via le synchronizer', function () {
    (new PermissionSeeder())->run();
    (new PermissionSeeder())->run();

    expect(Permission::query()->count())->toBe(count(PermissionCatalog::all()));
});
