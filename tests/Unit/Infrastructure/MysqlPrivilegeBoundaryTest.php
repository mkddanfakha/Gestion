<?php

use App\Database\DatabaseSafetyGuard;
use App\Database\ProtectedDatabaseException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * PRE-PROD 9 — MySQL privilege boundary (non-destructive tests only).
 */

afterEach(function () {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', ':memory:');
    Config::set('database.connections.mysql.database', 'gestion');
    Config::set('database.connections.mysql.username', 'root');
    Config::set('app.env', 'testing');
    DB::purge('sqlite');
});

test('ProductionDatabaseMustNeverBeUsedByTests', function () {
    expect(config('app.env'))->toBe('testing');
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    expect(DatabaseSafetyGuard::resolveDatabaseName())->not->toBe('gestion');
});

test('RuntimeDatabaseMustNotUseRoot reports root usage from mysql config', function () {
    Config::set('database.connections.mysql.username', 'root');
    expect(DatabaseSafetyGuard::isMysqlRuntimeUsingRoot())->toBeTrue();

    Config::set('database.connections.mysql.username', 'gestion_app');
    expect(DatabaseSafetyGuard::isMysqlRuntimeUsingRoot())->toBeFalse();
});

test('mysqlBoundaryStatus never exposes a password key', function () {
    $status = DatabaseSafetyGuard::mysqlBoundaryStatus();
    expect($status)->not->toHaveKey('password');
    expect($status)->not->toHaveKey('DB_PASSWORD');
    expect($status['password_exposed'])->toBeFalse();
    expect($status['recommended_runtime_account'])->toBe('gestion_app');
});

test('RestoreTargetMustBeAllowListed', function () {
    expect(fn () => DatabaseSafetyGuard::assertExplicitRestoreTarget('gestion_backup'))
        ->toThrow(ProtectedDatabaseException::class);
    DatabaseSafetyGuard::assertExplicitRestoreTarget('gestion_recovery');
    DatabaseSafetyGuard::assertExplicitRestoreTarget('gestion_test');
    expect(true)->toBeTrue();
});

test('GestionMustNeverBeRestoreTarget', function () {
    expect(fn () => DatabaseSafetyGuard::assertExplicitRestoreTarget('gestion'))
        ->toThrow(ProtectedDatabaseException::class);
    expect(fn () => DatabaseSafetyGuard::assertSafeForDropDatabase('gestion'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('DestructiveCommandsMustRemainBlocked on simulated gestion', function () {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', 'gestion');
    Config::set('database-safety.protected_databases', ['gestion']);
    DB::purge('sqlite');

    expect(fn () => Artisan::call('migrate:fresh', ['--force' => true]))
        ->toThrow(ProtectedDatabaseException::class);
    expect(fn () => Artisan::call('db:wipe', ['--force' => true]))
        ->toThrow(ProtectedDatabaseException::class);
    expect(fn () => Artisan::call('db:seed', ['--force' => true]))
        ->toThrow(ProtectedDatabaseException::class);
});

test('DatabaseSafetyGuardMustRemainActive', function () {
    expect(DatabaseSafetyGuard::isProtectedDatabase('gestion'))->toBeTrue();
    expect(DatabaseSafetyGuard::isDestructiveCommand('migrate:fresh'))->toBeTrue();
    expect(DatabaseSafetyGuard::isDestructiveCommand('db:seed'))->toBeTrue();
    expect(DatabaseSafetyGuard::restoreAllowedDatabases())->toContain('gestion_recovery');
    expect(DatabaseSafetyGuard::restoreAllowedDatabases())->toContain('gestion_test');
});

test('status exposes mysql privilege boundary without secrets', function () {
    Config::set('database.connections.mysql.username', 'root');
    $payload = DatabaseSafetyGuard::status('sqlite');
    expect($payload['mysql_username_is_root'])->toBeTrue();
    expect($payload['mysql_privilege_boundary'])->toBe('NOT_IMPLEMENTED');
    expect($payload)->not->toHaveKey('password');
});
