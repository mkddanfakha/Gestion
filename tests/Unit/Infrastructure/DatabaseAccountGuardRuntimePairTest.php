<?php

use App\Database\DatabaseAccountGuard;
use App\Database\DatabaseSafetyGuard;
use App\Database\ProtectedDatabaseException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * PRE-PROD 9.5.2E — Runtime guard validates (database, username) pairs.
 * Isolated Config only — no live MySQL / no production host.
 */
beforeEach(function () {
    Config::set('database-accounts.runtime_allowed_pairs', [
        ['database' => 'gestion', 'username' => 'gestion_app'],
        ['database' => 'mkdproqgestion', 'username' => 'mkdproqgestion'],
    ]);
});
afterEach(function () {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', ':memory:');
    Config::set('database.connections.mysql.database', 'gestion');
    Config::set('database.connections.mysql.username', 'gestion_app');
    Config::set('database-accounts.runtime_account', 'gestion_app');
    Config::set('database-accounts.env_cutover_executed', true);
    Config::set('database-accounts.runtime_allowed_pairs', [
        ['database' => 'gestion', 'username' => 'gestion_app'],
        ['database' => 'mkdproqgestion', 'username' => 'mkdproqgestion'],
    ]);
    Config::set('app.env', 'testing');
    DB::purge('sqlite');
});

function assertRuntimePair(string $database, string $username): void
{
    Config::set('database.connections.mysql.database', $database);
    Config::set('database.connections.mysql.username', $username);
    DatabaseAccountGuard::assertRuntimeUsernameAllowed();
}

function expectRuntimePairBlocked(string $database, string $username): void
{
    Config::set('database.connections.mysql.database', $database);
    Config::set('database.connections.mysql.username', $username);
    expect(fn () => DatabaseAccountGuard::assertRuntimeUsernameAllowed())
        ->toThrow(ProtectedDatabaseException::class);
}

test('TEST 1: gestion + gestion_app is ALLOW', function () {
    assertRuntimePair('gestion', 'gestion_app');
    expect(DatabaseAccountGuard::isAllowedRuntimePair('gestion', 'gestion_app'))->toBeTrue();
});

test('TEST 2: mkdproqgestion + mkdproqgestion is ALLOW', function () {
    assertRuntimePair('mkdproqgestion', 'mkdproqgestion');
    expect(DatabaseAccountGuard::isAllowedRuntimePair('mkdproqgestion', 'mkdproqgestion'))->toBeTrue();
});

test('TEST 3: gestion + mkdproqgestion is BLOCK', function () {
    expectRuntimePairBlocked('gestion', 'mkdproqgestion');
});

test('TEST 4: gestion_recovery + mkdproqgestion is BLOCK', function () {
    expectRuntimePairBlocked('gestion_recovery', 'mkdproqgestion');
});

test('TEST 5: gestion_test + mkdproqgestion is BLOCK', function () {
    expectRuntimePairBlocked('gestion_test', 'mkdproqgestion');
});

test('TEST 6: gestion_recovery + gestion_app is BLOCK', function () {
    expectRuntimePairBlocked('gestion_recovery', 'gestion_app');
});

test('TEST 7: gestion_test + gestion_app is BLOCK', function () {
    expectRuntimePairBlocked('gestion_test', 'gestion_app');
});

test('TEST 8: unknown database + mkdproqgestion is BLOCK', function () {
    expectRuntimePairBlocked('autre_base', 'mkdproqgestion');
});

test('TEST 9: mkdproqgestion + unknown user is BLOCK', function () {
    expectRuntimePairBlocked('mkdproqgestion', 'autre_utilisateur');
});

test('TEST 10: empty or missing database/username is BLOCK fail-closed', function () {
    expectRuntimePairBlocked('', 'gestion_app');
    expectRuntimePairBlocked('gestion', '');
    expectRuntimePairBlocked('', '');

    expect(DatabaseAccountGuard::isAllowedRuntimePair(null, 'gestion_app'))->toBeFalse();
    expect(DatabaseAccountGuard::isAllowedRuntimePair('gestion', null))->toBeFalse();
});

test('env_cutover_executed remains enforced for production pair and dangerous pairs', function () {
    expect(config('database-accounts.env_cutover_executed'))->toBeTrue();

    assertRuntimePair('mkdproqgestion', 'mkdproqgestion');
    expectRuntimePairBlocked('gestion', 'mkdproqgestion');

    Config::set('database-accounts.env_cutover_executed', false);
    Config::set('database.connections.mysql.database', 'gestion');
    Config::set('database.connections.mysql.username', 'mkdproqgestion');
    // Pre-cutover: pair mismatch not enforced; root/privileged still blocked elsewhere.
    DatabaseAccountGuard::assertRuntimeUsernameAllowed();
});

test('DB_APP_USERNAME never grants username-only global allow', function () {
    Config::set('database-accounts.runtime_account', 'mkdproqgestion');

    expectRuntimePairBlocked('gestion', 'mkdproqgestion');
    expectRuntimePairBlocked('gestion_recovery', 'mkdproqgestion');

    assertRuntimePair('mkdproqgestion', 'mkdproqgestion');

    Config::set('database-accounts.runtime_account', 'gestion_app');
    assertRuntimePair('gestion', 'gestion_app');
    expectRuntimePairBlocked('gestion_test', 'gestion_app');
});

test('username allow-list alone cannot authorize wrong database', function () {
    // Even if both usernames appear in allowed pairs, cross-pairing is forbidden.
    expect(DatabaseAccountGuard::isAllowedRuntimePair('gestion', 'mkdproqgestion'))->toBeFalse();
    expect(DatabaseAccountGuard::isAllowedRuntimePair('mkdproqgestion', 'gestion_app'))->toBeFalse();
});

test('backup restore privileged accounts remain blocked as runtime', function () {
    foreach (['gestion_backup', 'gestion_restore', 'gestion_migration', 'root'] as $user) {
        Config::set('database.connections.mysql.database', 'gestion');
        Config::set('database.connections.mysql.username', $user);
        expect(fn () => DatabaseAccountGuard::assertRuntimeUsernameAllowed())
            ->toThrow(ProtectedDatabaseException::class);
    }
});

test('backup restore safety layers unchanged for protected databases', function () {
    expect(DatabaseSafetyGuard::isProtectedDatabase('gestion'))->toBeTrue();
    expect(DatabaseSafetyGuard::isRestoreAllowedDatabase('gestion'))->toBeFalse();
    expect(DatabaseSafetyGuard::isRestoreAllowedDatabase('gestion_recovery'))->toBeTrue();
    expect(DatabaseSafetyGuard::isRestoreAllowedDatabase('gestion_test'))->toBeTrue();
    expect(DatabaseAccountGuard::restoreAccountName())->toBe('gestion_restore');
    expect(DatabaseAccountGuard::backupAccountName())->toBe('gestion_backup');

    expect(fn () => DatabaseAccountGuard::assertAccountForOperation(
        DatabaseAccountGuard::OPERATION_RESTORE,
        'gestion_app',
    ))->toThrow(ProtectedDatabaseException::class);

    expect(fn () => DatabaseAccountGuard::assertAccountForOperation(
        DatabaseAccountGuard::OPERATION_BACKUP,
        'gestion_app',
    ))->toThrow(ProtectedDatabaseException::class);
});

test('status never exposes password and reports pair match', function () {
    Config::set('database.connections.mysql.database', 'gestion');
    Config::set('database.connections.mysql.username', 'gestion_app');
    $status = DatabaseAccountGuard::status();

    expect($status['password_exposed'])->toBeFalse();
    expect($status)->not->toHaveKey('password');
    expect($status['runtime_matches_policy'])->toBeTrue();
    expect($status['configured_mysql_database'])->toBe('gestion');
});
test('single account mode allows runtime account when it is also the backup account', function () {
    Config::set('database-accounts.single_account_mode', true);
    Config::set('database-accounts.runtime_account', 'tswxwzgfallou');
    Config::set('database-accounts.backup_account', 'tswxwzgfallou');
    Config::set('database-accounts.runtime_allowed_pairs', 'tswxwzgfallou:tswxwzgfallou');
    Config::set('database-accounts.env_cutover_executed', true);

    Config::set('database.connections.mysql.database', 'tswxwzgfallou');
    Config::set('database.connections.mysql.username', 'tswxwzgfallou');

    DatabaseAccountGuard::assertRuntimeUsernameAllowed();

    expect(DatabaseAccountGuard::isAllowedRuntimePair(
        'tswxwzgfallou',
        'tswxwzgfallou'
    ))->toBeTrue();
});

test('single account mode does not allow mismatched runtime and backup accounts', function () {
    Config::set('database-accounts.single_account_mode', true);
    Config::set('database-accounts.runtime_account', 'tswxwzgfallou');
    Config::set('database-accounts.backup_account', 'gestion_backup');
    Config::set('database-accounts.env_cutover_executed', true);

    Config::set('database.connections.mysql.database', 'tswxwzgfallou');
    Config::set('database.connections.mysql.username', 'tswxwzgfallou');

    expect(fn () => DatabaseAccountGuard::assertRuntimeUsernameAllowed())
        ->toThrow(ProtectedDatabaseException::class);
});
