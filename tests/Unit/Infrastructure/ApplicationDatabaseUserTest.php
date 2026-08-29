<?php

use Illuminate\Support\Facades\Config;

/**
 * PRE-PROD 9.1/9.2 — Application DB user policy (no live MySQL user mutation).
 */

test('gestion_app is the configured runtime account name', function () {
    expect(config('database-accounts.runtime_account'))->toBe('gestion_app');
});

test('gestion_app is not root', function () {
    expect(strcasecmp((string) config('database-accounts.runtime_account'), 'root'))->not->toBe(0);
});

test('runtime privileges are CRUD only', function () {
    $privs = config('database-accounts.runtime_privileges');
    expect($privs)->toEqualCanonicalizing(['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
    expect($privs)->not->toContain('DROP');
    expect($privs)->not->toContain('CREATE');
    expect($privs)->not->toContain('ALTER');
    expect($privs)->not->toContain('GRANT OPTION');
});

test('forbidden runtime privileges include DROP CREATE GRANT', function () {
    $forbidden = config('database-accounts.forbidden_runtime_privileges');
    foreach (['DROP', 'CREATE', 'ALTER', 'GRANT OPTION', 'CREATE USER', 'CREATE DATABASE', 'DROP DATABASE'] as $p) {
        expect($forbidden)->toContain($p);
    }
});

test('runtime databases are limited to gestion', function () {
    expect(config('database-accounts.runtime_databases'))->toBe(['gestion']);
    expect(config('database-accounts.runtime_databases'))->not->toContain('*.*');
});

test('backup and restore accounts are separated in policy', function () {
    expect(config('database-accounts.backup_account'))->toBe('gestion_backup');
    expect(config('database-accounts.restore_account'))->toBe('gestion_restore');
    expect(config('database-accounts.migration_account'))->toBe('gestion_migration');
    expect(config('database-accounts.dba_account'))->toBe('root');
    expect(config('database-accounts.env_cutover_executed'))->toBeTrue();
    expect(config('database-accounts.backup_account_created'))->toBeTrue();
    expect(config('database-accounts.restore_account_created'))->toBeTrue();
    expect(config('database-accounts.migration_account_created'))->toBeTrue();
});

test('gestion is distinct from gestion_test and gestion_recovery', function () {
    expect(\App\Database\DatabaseSafetyGuard::isProtectedDatabase('gestion'))->toBeTrue();
    expect(\App\Database\DatabaseSafetyGuard::isProtectedDatabase('gestion_test'))->toBeFalse();
    expect(\App\Database\DatabaseSafetyGuard::isRestoreAllowedDatabase('gestion_recovery'))->toBeTrue();
    expect(\App\Database\DatabaseSafetyGuard::isRestoreAllowedDatabase('gestion'))->toBeFalse();
});

test('ProductionDatabaseMustNeverBeUsedByTests', function () {
    expect(config('app.env'))->toBe('testing');
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
});

test('runtime account policy is gestion_app after cutover', function () {
    expect(config('database-accounts.runtime_account'))->toBe('gestion_app');
    expect(config('database-accounts.env_cutover_executed'))->toBeTrue();
    Config::set('database.connections.mysql.username', 'gestion_app');
    expect(\App\Database\DatabaseSafetyGuard::isMysqlRuntimeUsingRoot())->toBeFalse();
});
