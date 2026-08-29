<?php

use App\Database\BackupConcurrencyGuard;
use App\Database\DatabaseSafetyGuard;
use App\Database\ProtectedDatabaseException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * PRE-PROD 8 — Bypass assurance. Never points PHPUnit at live MySQL gestion.
 */

beforeEach(function () {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', ':memory:');
    Config::set('database.connections.mysql.database', 'gestion');
    Config::set('database.connections.mysql.username', 'gestion_restore');
    Config::set('database-safety.protected_databases', ['gestion']);
    Config::set('database-safety.restore_allowed_databases', ['gestion_recovery', 'gestion_test']);
    Config::set('database-safety.destructive_commands', [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
        'db:seed',
    ]);
    Config::set('app.env', 'testing');
    BackupConcurrencyGuard::releaseBackup();
    BackupConcurrencyGuard::releaseRestore();
});

afterEach(function () {
    BackupConcurrencyGuard::releaseBackup();
    BackupConcurrencyGuard::releaseRestore();
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', ':memory:');
    Config::set('app.env', 'testing');
    DB::purge('sqlite');
});

function p8SimulateProtected(string $name = 'gestion'): void
{
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', $name);
    DB::purge('sqlite');
}

test('BYPASS allow-list exactness matrix', function () {
    $cases = [
        'gestion' => false,
        'GESTION' => false,
        'Gestion' => false,
        'gestion_test' => true,
        'gestion_recovery' => true,
        'gestion_backup' => false,
        'gestion_old' => false,
        'gestion2' => false,
        'my_gestion' => false,
        'gestion_recovery2' => false,
        'gestion_test_backup' => false,
    ];

    foreach ($cases as $name => $allowed) {
        expect(DatabaseSafetyGuard::isRestoreAllowedDatabase($name))
            ->toBe($allowed, "allow-list mismatch for {$name}");
        if ($name === 'gestion' || strcasecmp($name, 'gestion') === 0) {
            expect(DatabaseSafetyGuard::isProtectedDatabase($name))->toBeTrue();
        }
    }
});

test('BYPASS APP_ENV matrix does not disable protection', function (string $env) {
    Config::set('app.env', $env);
    p8SimulateProtected('gestion');

    expect(fn () => DatabaseSafetyGuard::assertDestructiveOperationAllowed('migrate:fresh'))
        ->toThrow(ProtectedDatabaseException::class);
    expect(fn () => DatabaseSafetyGuard::assertExplicitRestoreTarget('gestion'))
        ->toThrow(ProtectedDatabaseException::class);
})->with(['local', 'testing', 'production', 'staging']);

test('BYPASS Artisan::call migrate:fresh --force on gestion is blocked', function () {
    p8SimulateProtected('gestion');
    expect(fn () => Artisan::call('migrate:fresh', ['--force' => true]))
        ->toThrow(ProtectedDatabaseException::class);
});

test('BYPASS Artisan::call migrate:refresh --force on gestion is blocked', function () {
    p8SimulateProtected('gestion');
    expect(fn () => Artisan::call('migrate:refresh', ['--force' => true]))
        ->toThrow(ProtectedDatabaseException::class);
});

test('BYPASS Artisan::call migrate:reset --force on gestion is blocked', function () {
    p8SimulateProtected('gestion');
    expect(fn () => Artisan::call('migrate:reset', ['--force' => true]))
        ->toThrow(ProtectedDatabaseException::class);
});

test('BYPASS Artisan::call db:wipe --force on gestion is blocked', function () {
    p8SimulateProtected('gestion');
    expect(fn () => Artisan::call('db:wipe', ['--force' => true]))
        ->toThrow(ProtectedDatabaseException::class);
});

test('BYPASS Artisan::call db:seed --force on gestion is blocked', function () {
    p8SimulateProtected('gestion');
    expect(fn () => Artisan::call('db:seed', ['--force' => true]))
        ->toThrow(ProtectedDatabaseException::class);
});

test('BYPASS Artisan::call db:restore target gestion is blocked even with --force', function () {
    $exit = Artisan::call('db:restore', [
        '--backup' => 'missing.zip',
        '--target' => 'gestion',
        '--confirmation' => 'RESTORE',
        '--force' => true,
    ]);
    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('DATABASE SAFETY BLOCK');
});

test('BYPASS DROP DATABASE gestion is blocked by guard', function () {
    expect(fn () => DatabaseSafetyGuard::assertSafeForDropDatabase('gestion'))
        ->toThrow(ProtectedDatabaseException::class);
    expect(fn () => DatabaseSafetyGuard::assertSafeForDropDatabase('GESTION'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('BYPASS restore without explicit target cannot use mysql config gestion', function () {
    Config::set('database.connections.mysql.database', 'gestion');
    expect(fn () => DatabaseSafetyGuard::assertSafeForRestore(null))
        ->toThrow(ProtectedDatabaseException::class);
});

test('BYPASS HTTP-style restore payload target=gestion is blocked', function () {
    expect(fn () => DatabaseSafetyGuard::assertExplicitRestoreTarget('gestion'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('BYPASS HTTP-style restore payload target=gestion_backup is blocked', function () {
    expect(fn () => DatabaseSafetyGuard::assertExplicitRestoreTarget('gestion_backup'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('POSITIVE gestion_recovery and gestion_test restore targets allowed by policy', function () {
    DatabaseSafetyGuard::assertExplicitRestoreTarget('gestion_recovery');
    DatabaseSafetyGuard::assertExplicitRestoreTarget('gestion_test');
    expect(true)->toBeTrue();
});

test('POSITIVE sqlite :memory: is not protected', function () {
    p8SimulateProtected(':memory:');
    expect(DatabaseSafetyGuard::isProtectedConnection())->toBeFalse();
});

test('POSITIVE migrate:fresh allowed on sqlite :memory:', function () {
    p8SimulateProtected(':memory:');
    $exit = Artisan::call('migrate:fresh', ['--force' => true]);
    expect($exit)->toBe(0);
});

test('POSITIVE db:seed allowed on sqlite :memory:', function () {
    p8SimulateProtected(':memory:');
    Artisan::call('migrate:fresh', ['--force' => true]);
    // Seed may fail if factories need more setup; only assert it is NOT a ProtectedDatabaseException.
    try {
        Artisan::call('db:seed', ['--force' => true, '--class' => 'Database\\Seeders\\DatabaseSeeder']);
    } catch (ProtectedDatabaseException $e) {
        expect(false)->toBeTrue('db:seed must not be safety-blocked on :memory:');
    } catch (Throwable) {
        // Seeder runtime errors are OK for this safety test.
    }
    expect(true)->toBeTrue();
});

test('TEST DATABASE for this suite is never gestion', function () {
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    expect(DatabaseSafetyGuard::resolveDatabaseName())->not->toBe('gestion');
});

test('composer setup risk is documented as migrate --force not wipe', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $setup = $composer['scripts']['setup'] ?? [];
    expect($setup)->toContain('@php artisan migrate --force');
    expect(implode(' ', $setup))->not->toContain('migrate:fresh');
    expect(implode(' ', $setup))->not->toContain('db:wipe');
});

test('scripts directory has no PHP bootstrap restore/migrate', function () {
    $files = File::files(base_path('scripts'));
    foreach ($files as $file) {
        expect(strtolower($file->getExtension()))->not->toBe('php');
        $contents = File::get($file->getPathname());
        expect($contents)->not->toContain('migrate:fresh');
        expect($contents)->not->toContain('Artisan::call');
    }
});

test('MysqlPdoDumpImporter refuses dump that drops gestion', function () {
    $importer = new \App\Services\Restore\MysqlPdoDumpImporter();
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mkd-drop-gestion-'.uniqid().'.sql';
    file_put_contents($tmp, "DROP DATABASE IF EXISTS `gestion`;\nCREATE TABLE t (id int);\n");
    expect(fn () => $importer->import($tmp, 'gestion_recovery'))
        ->toThrow(RuntimeException::class);
    unlink($tmp);
});
