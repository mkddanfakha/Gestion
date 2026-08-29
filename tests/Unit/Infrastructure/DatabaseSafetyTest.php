<?php

use App\Database\DatabaseSafetyGuard;
use App\Database\ProtectedDatabaseException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kill-switch tests: never point at the real MySQL "gestion" business database.
 * Protected name is simulated on the sqlite connection only.
 *
 * Intentionally NOT using RefreshDatabase (would call migrate:fresh before each test).
 */

afterEach(function () {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', ':memory:');
    Config::set('database-safety.protected_databases', ['gestion']);
    Config::set('app.env', 'testing');
    DB::purge('sqlite');
});

function simulateProtectedSqliteDatabase(string $databaseName = 'gestion'): void
{
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', $databaseName);
    Config::set('database-safety.protected_databases', ['gestion']);
    DB::purge('sqlite');
}

function simulateUnprotectedSqliteDatabase(string $databaseName = ':memory:'): void
{
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', $databaseName);
    Config::set('database-safety.protected_databases', ['gestion']);
    DB::purge('sqlite');
}

test('testing environment must never resolve to protected database gestion', function () {
    expect(config('app.env'))->toBe('testing');
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    expect(DatabaseSafetyGuard::isProtectedConnection())->toBeFalse();
    expect(DatabaseSafetyGuard::resolveDatabaseName())->not->toBe('gestion');
});

test('exact match: gestion is protected, gestion_test is not', function () {
    Config::set('database-safety.protected_databases', ['gestion']);

    expect(DatabaseSafetyGuard::isProtectedDatabase('gestion'))->toBeTrue();
    expect(DatabaseSafetyGuard::isProtectedDatabase('gestion_test'))->toBeFalse();
    // Case variants of the protected name must also match (Windows MySQL).
    expect(DatabaseSafetyGuard::isProtectedDatabase('Gestion'))->toBeTrue();
    expect(DatabaseSafetyGuard::isProtectedDatabase('GESTION'))->toBeTrue();
});

test('assertDestructiveOperationAllowed blocks protected database regardless of APP_ENV local', function () {
    Config::set('app.env', 'local');
    simulateProtectedSqliteDatabase('gestion');

    expect(fn () => DatabaseSafetyGuard::assertDestructiveOperationAllowed('migrate:fresh'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('assertDestructiveOperationAllowed blocks protected database even when APP_ENV=testing', function () {
    Config::set('app.env', 'testing');
    simulateProtectedSqliteDatabase('gestion');

    expect(fn () => DatabaseSafetyGuard::assertDestructiveOperationAllowed('migrate:fresh'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('assertDestructiveOperationAllowed blocks protected database when APP_ENV=production', function () {
    Config::set('app.env', 'production');
    simulateProtectedSqliteDatabase('gestion');

    expect(fn () => DatabaseSafetyGuard::assertDestructiveOperationAllowed('migrate:fresh'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('ProductionDatabaseMustNeverBeUsedByTests', function () {
    expect(config('app.env'))->toBe('testing');
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');

    $defaultName = DatabaseSafetyGuard::resolveDatabaseName();
    expect($defaultName)->not->toBe('gestion');
    expect(DatabaseSafetyGuard::isProtectedDatabase($defaultName))->toBeFalse();

    $mysqlName = config('database.connections.mysql.database');
    if (is_string($mysqlName) && $mysqlName !== '') {
        expect(DatabaseSafetyGuard::isProtectedDatabase($mysqlName))->toBeFalse(
            'PHPUnit must not resolve mysql connection to a protected business database',
        );
    }
});

test('migrate:fresh is blocked on protected database name', function () {
    simulateProtectedSqliteDatabase('gestion');

    expect(fn () => Artisan::call('migrate:fresh', ['--force' => true]))
        ->toThrow(ProtectedDatabaseException::class);
});

test('migrate:refresh is blocked on protected database name', function () {
    simulateProtectedSqliteDatabase('gestion');

    expect(fn () => Artisan::call('migrate:refresh', ['--force' => true]))
        ->toThrow(ProtectedDatabaseException::class);
});

test('migrate:reset is blocked on protected database name', function () {
    simulateProtectedSqliteDatabase('gestion');

    expect(fn () => Artisan::call('migrate:reset', ['--force' => true]))
        ->toThrow(ProtectedDatabaseException::class);
});

test('db:wipe is blocked on protected database name', function () {
    simulateProtectedSqliteDatabase('gestion');

    expect(fn () => Artisan::call('db:wipe', ['--force' => true]))
        ->toThrow(ProtectedDatabaseException::class);
});

test('--force does not bypass protection on migrate:fresh', function () {
    simulateProtectedSqliteDatabase('gestion');

    expect(fn () => Artisan::call('migrate:fresh', ['--force' => true]))
        ->toThrow(ProtectedDatabaseException::class);
});

test('Artisan::call migrate:fresh is blocked on protected database', function () {
    Config::set('app.env', 'local');
    simulateProtectedSqliteDatabase('gestion');

    try {
        Artisan::call('migrate:fresh', ['--force' => true]);
        expect(false)->toBeTrue('Expected ProtectedDatabaseException');
    } catch (ProtectedDatabaseException $e) {
        expect($e->getMessage())->toContain('DATABASE SAFETY BLOCK');
        expect($e->getMessage())->toContain('gestion');
        expect($e->getMessage())->toContain('--force does not bypass');
    }
});

test('migrate:fresh is allowed on sqlite :memory: and gestion_test is not confused with gestion', function () {
    expect(DatabaseSafetyGuard::isProtectedDatabase('gestion_test'))->toBeFalse();

    simulateUnprotectedSqliteDatabase(':memory:');

    $exit = Artisan::call('migrate:fresh', ['--force' => true]);
    expect($exit)->toBe(0);
    expect(Schema::hasTable('migrations'))->toBeTrue();
});

test('db:safety-check is read-only and reports unprotected status', function () {
    simulateUnprotectedSqliteDatabase(':memory:');

    $exit = Artisan::call('db:safety-check', ['--json' => true]);
    expect($exit)->toBe(0);

    $payload = json_decode(Artisan::output(), true);
    expect($payload)->toBeArray();
    expect($payload['database'])->toBe(':memory:');
    expect($payload['protected'])->toBeFalse();
    expect($payload['status'])->toBe('UNPROTECTED');
});

test('db:safety-check reports PROTECTED when database name is gestion', function () {
    simulateProtectedSqliteDatabase('gestion');

    $exit = Artisan::call('db:safety-check', ['--json' => true]);
    expect($exit)->toBe(0);

    $payload = json_decode(Artisan::output(), true);
    expect($payload['database'])->toBe('gestion');
    expect($payload['protected'])->toBeTrue();
    expect($payload['status'])->toBe('PROTECTED');
    expect($payload['destructive_migrations'])->toBe('BLOCKED');
    expect($payload['database_wipe'])->toBe('BLOCKED');
});
