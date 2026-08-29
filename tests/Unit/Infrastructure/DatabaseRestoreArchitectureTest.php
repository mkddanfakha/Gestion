<?php

use App\Database\BackupConcurrencyGuard;
use App\Database\DatabaseSafetyGuard;
use App\Database\ProtectedDatabaseException;
use App\Services\Restore\ApplicationFilesRestoreService;
use App\Services\Restore\DatabaseRestoreService;
use App\Services\Restore\SqlDumpImporter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class FakeSqlDumpImporter implements SqlDumpImporter
{
    /** @var list<array{target: string, sql: string}> */
    public array $imports = [];

    public function import(string $sqlPath, string $explicitTarget): void
    {
        DatabaseSafetyGuard::assertExplicitRestoreTarget($explicitTarget);

        if (! is_file($sqlPath)) {
            throw new RuntimeException('SQL dump is empty or corrupted.');
        }

        $sql = (string) file_get_contents($sqlPath);
        if (trim($sql) === '' || ! str_contains(strtolower($sql), 'create table')) {
            throw new RuntimeException('SQL dump is empty or corrupted.');
        }

        $this->imports[] = ['target' => $explicitTarget, 'sql' => $sql];
    }
}

function makeRestoreTestZip(string $sql, string $filename = 'drill-test.zip'): string
{
    $folder = config('backup.backup.name', 'laravel-backup');
    $root = config('filesystems.disks.local.root');
    $dir = $root.DIRECTORY_SEPARATOR.$folder;
    File::ensureDirectoryExists($dir);
    $path = $dir.DIRECTORY_SEPARATOR.$filename;

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('db-dumps/mysql-gestion.sql', $sql);
    $zip->close();

    return $filename;
}

beforeEach(function () {
    ApplicationFilesRestoreService::$invokeCount = 0;
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', ':memory:');
    Config::set('database.connections.mysql.database', 'gestion');
    Config::set('database.connections.mysql.username', 'gestion_restore');
    Config::set('database-safety.protected_databases', ['gestion']);
    Config::set('database-safety.restore_allowed_databases', ['gestion_recovery', 'gestion_test']);
    Config::set('app.env', 'testing');
    app()->instance(SqlDumpImporter::class, new FakeSqlDumpImporter());
});

afterEach(function () {
    BackupConcurrencyGuard::releaseBackup();
    BackupConcurrencyGuard::releaseRestore();
    ApplicationFilesRestoreService::$invokeCount = 0;
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', ':memory:');
});

test('TEST 1 target gestion is blocked', function () {
    expect(fn () => app(DatabaseRestoreService::class)->restore('x.zip', 'gestion', 'RESTORE'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('TEST 2 target gestion with --force is blocked', function () {
    expect(fn () => app(DatabaseRestoreService::class)->restore('x.zip', 'gestion', 'RESTORE', true))
        ->toThrow(ProtectedDatabaseException::class);
});

test('TEST 3 Artisan::call db:restore target gestion is blocked', function () {
    $exit = Artisan::call('db:restore', [
        '--backup' => 'x.zip',
        '--target' => 'gestion',
        '--confirmation' => 'RESTORE',
        '--force' => true,
    ]);
    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('DATABASE SAFETY BLOCK');
});

test('TEST 4 HTTP restore target gestion is blocked by explicit target guard', function () {
    Config::set('app.env', 'production');
    expect(fn () => DatabaseSafetyGuard::assertExplicitRestoreTarget('gestion'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('TEST 5 missing target is blocked', function () {
    expect(fn () => DatabaseSafetyGuard::assertExplicitRestoreTarget(null))
        ->toThrow(ProtectedDatabaseException::class);
    expect(fn () => DatabaseSafetyGuard::assertSafeForRestore(null))
        ->toThrow(ProtectedDatabaseException::class);
    expect(fn () => DatabaseSafetyGuard::assertSafeForRestore(''))
        ->toThrow(ProtectedDatabaseException::class);

    $exit = Artisan::call('db:restore', ['--backup' => 'x.zip', '--confirmation' => 'RESTORE']);
    expect($exit)->toBe(1);
});

test('TEST 6 unknown target is blocked', function () {
    expect(fn () => DatabaseSafetyGuard::assertExplicitRestoreTarget('production'))
        ->toThrow(ProtectedDatabaseException::class);
    expect(fn () => DatabaseSafetyGuard::assertExplicitRestoreTarget('Gestion'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('TEST 7 target gestion_recovery is allowed when backup is valid', function () {
    $file = makeRestoreTestZip("CREATE TABLE `customers` (id int);\nINSERT INTO `customers` VALUES (1);\n");
    $fake = app(SqlDumpImporter::class);
    expect($fake)->toBeInstanceOf(FakeSqlDumpImporter::class);

    $report = app(DatabaseRestoreService::class)->restore($file, 'gestion_recovery', 'RESTORE');
    expect($report['target'])->toBe('gestion_recovery');
    expect($report['files_touched'])->toBeFalse();
    expect($report['application_files_restore_invoked'])->toBeFalse();
    expect($fake->imports)->toHaveCount(1);
    expect($fake->imports[0]['target'])->toBe('gestion_recovery');
});

test('TEST 8 DatabaseRestoreService does not depend on ApplicationFilesRestoreService', function () {
    $params = (new ReflectionClass(DatabaseRestoreService::class))->getConstructor()?->getParameters() ?? [];
    $types = array_map(fn (ReflectionParameter $p) => $p->getType()?->__toString(), $params);
    expect($types)->not->toContain(ApplicationFilesRestoreService::class);
});

test('TEST 9 restoreFiles is not invoked during DB restore', function () {
    $file = makeRestoreTestZip("CREATE TABLE `users` (id int);\n");
    ApplicationFilesRestoreService::$invokeCount = 0;
    app(DatabaseRestoreService::class)->restore($file, 'gestion_test', 'RESTORE');
    expect(ApplicationFilesRestoreService::$invokeCount)->toBe(0);
});

test('TEST 10 script-style restore of gestion via protected service is blocked', function () {
    expect(fn () => app(DatabaseRestoreService::class)->restore('any.zip', 'gestion', 'RESTORE'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('TEST 11 wrong confirmation is blocked', function () {
    expect(fn () => app(DatabaseRestoreService::class)->restore('x.zip', 'gestion_recovery', 'yes'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('TEST 12 restore lock blocks a second restore', function () {
    BackupConcurrencyGuard::acquireRestore(60);
    $file = makeRestoreTestZip("CREATE TABLE `users` (id int);\n");
    expect(fn () => app(DatabaseRestoreService::class)->restore($file, 'gestion_recovery', 'RESTORE'))
        ->toThrow(RuntimeException::class);
});

test('TEST 13 invalid backup is blocked', function () {
    expect(fn () => app(DatabaseRestoreService::class)->restore('missing-file.zip', 'gestion_recovery', 'RESTORE'))
        ->toThrow(RuntimeException::class);
});

test('TEST 14 corrupted SQL dump is blocked', function () {
    $file = makeRestoreTestZip("   \n");
    expect(fn () => app(DatabaseRestoreService::class)->restore($file, 'gestion_recovery', 'RESTORE'))
        ->toThrow(RuntimeException::class);
});

test('TEST 15 default app database remains gestion in mysql config and is never used as target', function () {
    Config::set('database.connections.mysql.database', 'gestion');
    $file = makeRestoreTestZip("CREATE TABLE `users` (id int);\n");
    $report = app(DatabaseRestoreService::class)->restore($file, 'gestion_recovery', 'RESTORE');
    expect($report['target'])->toBe('gestion_recovery');
    expect(config('database.connections.mysql.database'))->toBe('gestion');
    expect(config('database.default'))->toBe('sqlite');
    expect(DatabaseSafetyGuard::resolveDatabaseName())->not->toBe('gestion_recovery');
});

test('restore target is never inferred from DB_DATABASE even if it is allow-listed', function () {
    Config::set('database.connections.mysql.database', 'gestion_recovery');
    expect(fn () => DatabaseSafetyGuard::assertSafeForRestore(null))
        ->toThrow(ProtectedDatabaseException::class);
});
