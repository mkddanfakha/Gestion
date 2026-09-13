<?php

use App\Database\DatabaseAccountGuard;
use App\Database\ProtectedDatabaseException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * PRE-PROD 9.5.4 — mysql migrate is gated by (database, username) pairs.
 */
beforeEach(function () {
    Config::set('database-accounts.migration_allowed_pairs', [
        ['database' => 'gestion', 'username' => 'gestion_app'],
        ['database' => 'mkdproqgestion', 'username' => 'mkdproqgestion'],
    ]);
});
afterEach(function () {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', ':memory:');
    Config::set('database.connections.mysql.database', 'gestion');
    Config::set('database.connections.mysql.username', 'gestion_app');
    Config::set('database-accounts.env_cutover_executed', true);
    DB::purge('sqlite');
});

test('migrate pair gestion + gestion_app is ALLOW', function () {
    DatabaseAccountGuard::assertAccountForOperation(
        DatabaseAccountGuard::OPERATION_MIGRATION,
        'gestion_app',
        'gestion',
    );
    expect(DatabaseAccountGuard::isAllowedMigrationPair('gestion', 'gestion_app'))->toBeTrue();
});

test('migrate pair mkdproqgestion + mkdproqgestion is ALLOW', function () {
    DatabaseAccountGuard::assertAccountForOperation(
        DatabaseAccountGuard::OPERATION_MIGRATION,
        'mkdproqgestion',
        'mkdproqgestion',
    );
    expect(true)->toBeTrue();
});

test('migrate pair gestion + mkdproqgestion is BLOCK', function () {
    expect(fn () => DatabaseAccountGuard::assertAccountForOperation(
        DatabaseAccountGuard::OPERATION_MIGRATION,
        'mkdproqgestion',
        'gestion',
    ))->toThrow(ProtectedDatabaseException::class);
});

test('migrate pair mkdproqgestion + gestion_app is BLOCK', function () {
    expect(fn () => DatabaseAccountGuard::assertAccountForOperation(
        DatabaseAccountGuard::OPERATION_MIGRATION,
        'gestion_app',
        'mkdproqgestion',
    ))->toThrow(ProtectedDatabaseException::class);
});

test('migrate pair unknown database + unknown user is BLOCK', function () {
    expect(fn () => DatabaseAccountGuard::assertAccountForOperation(
        DatabaseAccountGuard::OPERATION_MIGRATION,
        'autre_compte',
        'autre_base',
    ))->toThrow(ProtectedDatabaseException::class);
});

test('backup and restore still refuse gestion_app', function () {
    expect(fn () => DatabaseAccountGuard::assertAccountForOperation(
        DatabaseAccountGuard::OPERATION_BACKUP,
        'gestion_app',
        'gestion',
    ))->toThrow(ProtectedDatabaseException::class);

    expect(fn () => DatabaseAccountGuard::assertAccountForOperation(
        DatabaseAccountGuard::OPERATION_RESTORE,
        'gestion_app',
        'gestion',
    ))->toThrow(ProtectedDatabaseException::class);
});

test('username alone never allows migrate on the wrong database', function () {
    expect(DatabaseAccountGuard::isAllowedMigrationPair('gestion', 'mkdproqgestion'))->toBeFalse();
    expect(DatabaseAccountGuard::isAllowedMigrationPair('gestion_recovery', 'mkdproqgestion'))->toBeFalse();
    expect(DatabaseAccountGuard::isAllowedMigrationPair('gestion_recovery', 'gestion_app'))->toBeFalse();
});
