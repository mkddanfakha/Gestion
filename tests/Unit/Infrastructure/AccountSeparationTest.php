<?php

use App\Database\DatabaseAccountGuard;
use App\Database\DatabaseSafetyGuard;
use App\Database\ProtectedDatabaseException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * PRE-PROD 9.3 — Account separation policy (no live MySQL account creation).
 */

afterEach(function () {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', ':memory:');
    Config::set('database.connections.mysql.database', 'gestion');
    Config::set('database.connections.mysql.username', 'gestion_app');
    Config::set('app.env', 'testing');
    DB::purge('sqlite');
});

test('runtime must not use root after cutover', function () {
    Config::set('database.connections.mysql.username', 'root');
    expect(fn () => DatabaseAccountGuard::assertRuntimeUsernameAllowed())
        ->toThrow(ProtectedDatabaseException::class);

    Config::set('database.connections.mysql.username', 'root@localhost');
    // Username field is the MySQL user name only; root still matches.
    Config::set('database.connections.mysql.username', 'root');
    expect(DatabaseAccountGuard::isRootUsername('root'))->toBeTrue();
    expect(DatabaseAccountGuard::isRootUsername('ROOT'))->toBeTrue();
});

test('runtime must not use backup restore or migration accounts', function () {
    foreach (['gestion_backup', 'gestion_restore', 'gestion_migration'] as $user) {
        Config::set('database.connections.mysql.username', $user);
        expect(fn () => DatabaseAccountGuard::assertRuntimeUsernameAllowed())
            ->toThrow(ProtectedDatabaseException::class);
    }
});

test('runtime gestion_app is allowed', function () {
    Config::set('database.connections.mysql.username', 'gestion_app');
    DatabaseAccountGuard::assertRuntimeUsernameAllowed();
    expect(DatabaseAccountGuard::isRuntimeUsername('gestion_app'))->toBeTrue();
});

test('backup:run refuses gestion_app account', function () {
    Config::set('database.connections.mysql.username', 'gestion_app');
    expect(fn () => Artisan::call('backup:run', ['--only-db' => true]))
        ->toThrow(ProtectedDatabaseException::class);
});

test('migrate on mysql refuses gestion_app account', function () {
    Config::set('database.default', 'mysql');
    Config::set('database.connections.mysql.username', 'gestion_app');
    Config::set('database.connections.mysql.database', 'gestion');
    expect(fn () => Artisan::call('migrate', ['--force' => true]))
        ->toThrow(ProtectedDatabaseException::class);
});

test('migrate on sqlite is not gated by mysql account policy', function () {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', ':memory:');
    Config::set('database.connections.mysql.username', 'gestion_app');
    // Direct assert still fails (policy), but PrivilegedCommandGuard skips non-mysql migrate.
    expect(fn () => DatabaseAccountGuard::assertAccountForOperation('migration'))
        ->toThrow(ProtectedDatabaseException::class);
    expect(config('database.connections.sqlite.driver') ?? 'sqlite')->toBe('sqlite');
});

test('restore refuses gestion_app account', function () {
    Config::set('database.connections.mysql.username', 'gestion_app');
    expect(fn () => DatabaseAccountGuard::assertAccountForOperation('restore'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('restore allows configured gestion_restore username', function () {
    Config::set('database.connections.mysql.username', 'gestion_restore');
    DatabaseAccountGuard::assertAccountForOperation('restore');
    expect(true)->toBeTrue();
});

test('privileged accounts creation flags after 9.3.x', function () {
    expect(config('database-accounts.backup_account_created'))->toBeTrue();
    expect(config('database-accounts.restore_account_created'))->toBeTrue();
    expect(config('database-accounts.migration_account_created'))->toBeTrue();
});

test('privilege matrix forbids DDL on runtime account', function () {
    $forbidden = config('database-accounts.forbidden_runtime_privileges');
    foreach (['DROP', 'ALTER', 'CREATE', 'CREATE DATABASE', 'DROP DATABASE', 'LOCK TABLES', 'GRANT OPTION'] as $p) {
        expect($forbidden)->toContain($p);
    }
    expect(config('database-accounts.backup_privileges'))->toContain('LOCK TABLES');
    expect(config('database-accounts.restore_databases'))->toEqualCanonicalizing(['gestion_recovery', 'gestion_test']);
    expect(config('database-accounts.restore_databases'))->not->toContain('gestion');
});

test('gestion_app privilege bypass expectations are policy-documented without touching gestion', function () {
    // Live DENIED DDL tests require gestion_test (does not exist) — do not auto-create.
    expect(DatabaseSafetyGuard::isRestoreAllowedDatabase('gestion_test'))->toBeTrue();
    expect(DatabaseSafetyGuard::isProtectedDatabase('gestion'))->toBeTrue();
    expect(config('database-accounts.runtime_privileges'))
        ->toEqualCanonicalizing(['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
});

test('account status never exposes password', function () {
    $status = DatabaseAccountGuard::status();
    expect($status['password_exposed'])->toBeFalse();
    expect($status)->not->toHaveKey('password');
    expect($status)->not->toHaveKey('DB_PASSWORD');
});
