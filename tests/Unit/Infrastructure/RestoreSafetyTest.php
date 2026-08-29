<?php

use App\Database\DatabaseSafetyGuard;
use App\Database\ProtectedDatabaseException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

afterEach(function () {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', ':memory:');
    Config::set('database.connections.mysql.database', 'gestion');
    Config::set('database-safety.protected_databases', ['gestion']);
    Config::set('database-safety.restore_allowed_databases', ['gestion_recovery', 'gestion_test']);
    Config::set('app.env', 'testing');
    DB::purge('sqlite');
});

test('DROP DATABASE is blocked on protected database gestion', function () {
    expect(fn () => DatabaseSafetyGuard::assertSafeForDropDatabase('gestion'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('restore is blocked when target is missing even if mysql config is gestion_recovery', function () {
    Config::set('database.connections.mysql.database', 'gestion_recovery');

    expect(fn () => DatabaseSafetyGuard::assertSafeForRestore(null))
        ->toThrow(ProtectedDatabaseException::class);
});

test('restore is blocked when target is gestion', function () {
    Config::set('app.env', 'local');
    Config::set('database.connections.mysql.database', 'gestion');

    expect(fn () => DatabaseSafetyGuard::assertSafeForRestore('gestion'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('restore is blocked even when APP_ENV=testing and target is gestion', function () {
    Config::set('app.env', 'testing');

    expect(fn () => DatabaseSafetyGuard::assertSafeForRestore('gestion'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('restore is allowed for gestion_recovery per policy', function () {
    DatabaseSafetyGuard::assertSafeForRestore('gestion_recovery');
    DatabaseSafetyGuard::assertSafeForDropDatabase('gestion_recovery');
    expect(DatabaseSafetyGuard::isRestoreAllowedDatabase('gestion_recovery'))->toBeTrue();
});

test('restore is allowed for gestion_test per policy', function () {
    DatabaseSafetyGuard::assertSafeForRestore('gestion_test');
    expect(true)->toBeTrue();
});

test('restore is blocked for unknown database names (fail-closed)', function () {
    expect(fn () => DatabaseSafetyGuard::assertSafeForRestore('random_db'))
        ->toThrow(ProtectedDatabaseException::class);

    expect(fn () => DatabaseSafetyGuard::assertSafeForDropDatabase('random_db'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('admin-style APP_ENV does not authorize DROP DATABASE on gestion', function () {
    Config::set('app.env', 'production');

    expect(fn () => DatabaseSafetyGuard::assertSafeForDropDatabase('gestion'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('restore confirmation phrase must be exact RESTORE', function () {
    expect(fn () => DatabaseSafetyGuard::assertRestoreConfirmationPhrase('yes'))
        ->toThrow(ProtectedDatabaseException::class);

    expect(fn () => DatabaseSafetyGuard::assertRestoreConfirmationPhrase(null))
        ->toThrow(ProtectedDatabaseException::class);

    DatabaseSafetyGuard::assertRestoreConfirmationPhrase('RESTORE');
    expect(true)->toBeTrue();
});

test('confirm=true alone is not enough without confirmation_phrase', function () {
    // Semantic guard test: phrase check is independent of frontend confirm checkbox.
    expect(fn () => DatabaseSafetyGuard::assertRestoreConfirmationPhrase(''))
        ->toThrow(ProtectedDatabaseException::class);
});

test('db:safety-check reports restore BLOCKED for mysql gestion', function () {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', ':memory:');
    Config::set('database.connections.mysql.database', 'gestion');

    $exit = Artisan::call('db:safety-check', ['--json' => true]);
    expect($exit)->toBe(0);

    $payload = json_decode(Artisan::output(), true);
    expect($payload['restore'])->toBe('BLOCKED');
    expect($payload['drop_database'])->toBe('BLOCKED');
    expect($payload['mysql_database'])->toBe('gestion');
});

test('Artisan migrate:fresh remains blocked on gestion (regression)', function () {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', 'gestion');
    Config::set('database-safety.protected_databases', ['gestion']);
    DB::purge('sqlite');

    expect(fn () => Artisan::call('migrate:fresh', ['--force' => true]))
        ->toThrow(ProtectedDatabaseException::class);
});

test('exact match: gestion_recovery is not confused with gestion', function () {
    expect(DatabaseSafetyGuard::isProtectedDatabase('gestion_recovery'))->toBeFalse();
    expect(DatabaseSafetyGuard::isProtectedDatabase('gestion'))->toBeTrue();
});
