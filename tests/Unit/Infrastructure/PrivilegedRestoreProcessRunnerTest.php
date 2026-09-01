<?php

use App\Database\DatabaseAccountGuard;
use App\Database\DatabaseSafetyGuard;
use App\Database\PrivilegedCredentialLoader;
use App\Database\PrivilegedProcessResult;
use App\Database\PrivilegedProcessRunner;
use App\Database\PrivilegedRestoreProcessRunner;
use App\Database\ProtectedDatabaseException;
use App\Services\Backup\BackupManifestService;
use App\Services\Backup\BackupMetadataService;
use App\Services\Backup\BackupPathGuard;
use App\Services\Restore\DatabaseRestoreService;
use App\Services\Restore\SqlDumpImporter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

class RecordingPrivilegedRestoreRunner extends PrivilegedRestoreProcessRunner
{
    public int $runCount = 0;

    /** @var list<array{backup: string, target: string, phrase: string}> */
    public array $calls = [];

    public ?PrivilegedProcessResult $nextResult = null;

    public function runRestore(
        string $backupFileName,
        string $target,
        string $confirmationPhrase,
        ?int $timeoutSeconds = null,
    ): PrivilegedProcessResult {
        $this->runCount++;
        $this->calls[] = [
            'backup' => $backupFileName,
            'target' => $target,
            'phrase' => $confirmationPhrase,
        ];

        return $this->nextResult ?? new PrivilegedProcessResult(0, json_encode([
            'mode' => 'database',
            'backup' => $backupFileName,
            'target' => $target,
            'status' => 'imported',
        ]) ?: '{}', '');
    }
}

function makePrivilegedRestoreCredentialFile(string $username = 'gestion_restore', string $password = 'secret-restore'): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.PrivilegedCredentialLoader::RESTORE_CREDENTIAL_FILE;
    file_put_contents($path, implode("\n", [
        '# test restore credentials',
        "DB_USERNAME={$username}",
        "DB_PASSWORD={$password}",
        'DB_HOST=127.0.0.1',
        'DB_PORT=3306',
        'DB_DATABASE=gestion_recovery',
    ]));

    return $path;
}

beforeEach(function () {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', ':memory:');
    Config::set('database.connections.mysql.username', 'gestion_app');
    Config::set('database-safety.protected_databases', ['gestion']);
    Config::set('database-safety.restore_allowed_databases', ['gestion_recovery', 'gestion_test']);
    putenv(PrivilegedProcessRunner::SUBPROCESS_MARKER);
});

afterEach(function () {
    putenv(PrivilegedProcessRunner::SUBPROCESS_MARKER);
});

test('PrivilegedRestoreProcessRunner blocks gestion target before subprocess', function () {
    $runner = new PrivilegedRestoreProcessRunner(
        new PrivilegedCredentialLoader,
        new PrivilegedProcessRunner(new PrivilegedCredentialLoader),
    );

    expect(fn () => $runner->assertRestoreTargetAllowed('gestion'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('PrivilegedRestoreProcessRunner allow-list matrix', function () {
    $runner = new PrivilegedRestoreProcessRunner(
        new PrivilegedCredentialLoader,
        new PrivilegedProcessRunner(new PrivilegedCredentialLoader),
    );

    expect($runner->assertRestoreTargetAllowed('gestion_recovery'))->toBe('gestion_recovery');
    expect($runner->assertRestoreTargetAllowed('gestion_test'))->toBe('gestion_test');

    expect(fn () => $runner->assertRestoreTargetAllowed('gestion'))
        ->toThrow(ProtectedDatabaseException::class);
    expect(fn () => $runner->assertRestoreTargetAllowed('foo'))
        ->toThrow(ProtectedDatabaseException::class);
    expect(fn () => $runner->assertRestoreTargetAllowed('gestion_recovery;DROP DATABASE gestion'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('restore credential loader requires gestion_restore and rejects gestion_app', function () {
    $loader = new PrivilegedCredentialLoader;
    $badPath = makePrivilegedRestoreCredentialFile('gestion_app', 'secret');
    expect(fn () => $loader->loadRestoreCredentials($badPath))
        ->toThrow(RuntimeException::class);
    @unlink($badPath);

    $backupPath = makePrivilegedRestoreCredentialFile('gestion_backup', 'secret');
    expect(fn () => $loader->loadRestoreCredentials($backupPath))
        ->toThrow(RuntimeException::class);
    @unlink($backupPath);

    $okPath = makePrivilegedRestoreCredentialFile('gestion_restore', 'secret-restore');
    $creds = $loader->loadRestoreCredentials($okPath);
    expect($creds['username'])->toBe('gestion_restore');
    expect($creds['host'])->toBe('127.0.0.1');
    expect($creds['port'])->toBe('3306');
    @unlink($okPath);
});

test('restore credential loader never exposes password in exceptions', function () {
    $loader = new PrivilegedCredentialLoader;
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.PrivilegedCredentialLoader::RESTORE_CREDENTIAL_FILE;
    file_put_contents($path, "DB_USERNAME=gestion_restore\nDB_PASSWORD=\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=gestion_recovery\n");

    try {
        $loader->loadRestoreCredentials($path);
        expect(false)->toBeTrue('expected exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('secret');
        expect($e->getMessage())->toContain('DB_PASSWORD');
    } finally {
        @unlink($path);
    }
});

test('PrivilegedRestoreProcessRunner buildRestoreSubprocessEnvironment uses validated target', function () {
    $runner = new PrivilegedRestoreProcessRunner(
        new PrivilegedCredentialLoader,
        new PrivilegedProcessRunner(new PrivilegedCredentialLoader),
    );

    $env = $runner->buildRestoreSubprocessEnvironment([
        'username' => 'gestion_restore',
        'password' => 'secret-restore',
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'gestion_recovery',
        'source' => 'test.local',
    ], 'gestion_test');

    expect($env['DB_USERNAME'])->toBe('gestion_restore');
    expect($env['DB_DATABASE'])->toBe('gestion_test');
    expect($env[PrivilegedProcessRunner::SUBPROCESS_MARKER])->toBe(PrivilegedProcessRunner::SUBPROCESS_OPERATION_RESTORE);
    expect($env['DB_PASSWORD'])->toBe('secret-restore');
});

test('PrivilegedRestoreProcessRunner refuses secret in CLI arguments', function () {
    $loader = new PrivilegedCredentialLoader;
    $runner = new PrivilegedProcessRunner($loader);
    $restoreRunner = new PrivilegedRestoreProcessRunner($loader, $runner);

    $secret = 'super-secret-restore-password';
    $command = $runner->buildArtisanCommand('db:restore', [
        '--backup' => 'test.zip',
        '--target' => 'gestion_recovery',
        '--confirmation' => 'RESTORE',
        $secret,
    ]);

    $reflection = new ReflectionClass(PrivilegedRestoreProcessRunner::class);
    $method = $reflection->getMethod('assertCommandLineContainsNoSecret');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($restoreRunner, $command, $secret))
        ->toThrow(RuntimeException::class);
});

test('DatabaseRestoreService runtime gestion_app delegates to privileged runner', function () {
    $recording = new RecordingPrivilegedRestoreRunner(
        new PrivilegedCredentialLoader,
        new PrivilegedProcessRunner(new PrivilegedCredentialLoader),
    );
    app()->instance(PrivilegedRestoreProcessRunner::class, $recording);

    $folder = config('backup.backup.name', 'laravel-backup');
    $root = config('filesystems.disks.local.root');
    $dir = $root.DIRECTORY_SEPARATOR.$folder;
    File::ensureDirectoryExists($dir);
    $zipPath = $dir.DIRECTORY_SEPARATOR.'delegate-test.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('db-dumps/mysql-gestion.sql', "CREATE TABLE `users` (id int);\n");
    $zip->close();

    Config::set('database.connections.mysql.username', 'gestion_app');

    $report = app(DatabaseRestoreService::class)->restore('delegate-test.zip', 'gestion_recovery', 'RESTORE');

    expect($recording->runCount)->toBe(1);
    expect($recording->calls[0]['target'])->toBe('gestion_recovery');
    expect($report['status'])->toBe('imported');
});

test('DatabaseRestoreService runtime does not start subprocess when backup missing', function () {
    $recording = new RecordingPrivilegedRestoreRunner(
        new PrivilegedCredentialLoader,
        new PrivilegedProcessRunner(new PrivilegedCredentialLoader),
    );
    app()->instance(PrivilegedRestoreProcessRunner::class, $recording);

    expect(fn () => app(DatabaseRestoreService::class)->restore('missing.zip', 'gestion_recovery', 'RESTORE'))
        ->toThrow(RuntimeException::class);

    expect($recording->runCount)->toBe(0);
});

test('DatabaseRestoreService subprocess failure surfaces error without success', function () {
    $recording = new RecordingPrivilegedRestoreRunner(
        new PrivilegedCredentialLoader,
        new PrivilegedProcessRunner(new PrivilegedCredentialLoader),
    );
    $recording->nextResult = new PrivilegedProcessResult(1, '', 'DATABASE ACCOUNT SAFETY BLOCK');
    app()->instance(PrivilegedRestoreProcessRunner::class, $recording);

    $folder = config('backup.backup.name', 'laravel-backup');
    $root = config('filesystems.disks.local.root');
    $dir = $root.DIRECTORY_SEPARATOR.$folder;
    File::ensureDirectoryExists($dir);
    $zipPath = $dir.DIRECTORY_SEPARATOR.'fail-test.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('db-dumps/mysql-gestion.sql', "CREATE TABLE `users` (id int);\n");
    $zip->close();

    expect(fn () => app(DatabaseRestoreService::class)->restore('fail-test.zip', 'gestion_recovery', 'RESTORE'))
        ->toThrow(RuntimeException::class);
});

test('DatabaseAccountGuard still refuses gestion_app for restore operation', function () {
    Config::set('database.connections.mysql.username', 'gestion_app');

    expect(fn () => DatabaseAccountGuard::assertAccountForOperation(DatabaseAccountGuard::OPERATION_RESTORE))
        ->toThrow(ProtectedDatabaseException::class);
});

test('db:restore is registered in PrivilegedCommandGuard operations', function () {
    $reflection = new ReflectionClass(\App\Database\PrivilegedCommandGuard::class);
    $constant = $reflection->getReflectionConstant('COMMAND_OPERATIONS');
    expect($constant)->not->toBeFalse();
    $operations = $constant->getValue();

    expect($operations)->toHaveKey('db:restore');
    expect($operations['db:restore'])->toBe(DatabaseAccountGuard::OPERATION_RESTORE);
});

test('sanitizeForLog redacts restore password from subprocess output', function () {
    $secret = 'restore-secret-value';
    $output = "Connected with password restore-secret-value and done";

    expect(PrivilegedCredentialLoader::sanitizeForLog($output, $secret))
        ->not->toContain($secret)
        ->toContain('[REDACTED]');
});

test('PrivilegedRestoreProcessRunner shell injection targets are denied', function () {
    $runner = new PrivilegedRestoreProcessRunner(
        new PrivilegedCredentialLoader,
        new PrivilegedProcessRunner(new PrivilegedCredentialLoader),
    );

    foreach ([
        'gestion_recovery && echo hacked',
        'gestion_recovery | cat /etc/passwd',
        'gestion_recovery $(id)',
        '../gestion',
    ] as $malicious) {
        expect(fn () => $runner->assertRestoreTargetAllowed($malicious))
            ->toThrow(ProtectedDatabaseException::class);
    }
});

test('DatabaseRestoreService does not start subprocess when SHA integrity fails', function () {
    $recording = new RecordingPrivilegedRestoreRunner(
        new PrivilegedCredentialLoader,
        new PrivilegedProcessRunner(new PrivilegedCredentialLoader),
    );
    app()->instance(PrivilegedRestoreProcessRunner::class, $recording);

    $folder = config('backup.backup.name', 'laravel-backup');
    $root = config('filesystems.disks.local.root');
    $dir = $root.DIRECTORY_SEPARATOR.$folder;
    File::ensureDirectoryExists($dir);
    $name = 'sha-invalid-restore.zip';
    $path = $dir.DIRECTORY_SEPARATOR.$name;

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('db-dumps/mysql-gestion.sql', "CREATE TABLE `users` (id int);\n");
    $zip->close();

    app(BackupManifestService::class)->createAndWriteForExistingZip($name, [
        'type' => BackupMetadataService::TYPE_DATABASE,
        'source' => BackupMetadataService::SOURCE_MANUAL,
    ]);

    file_put_contents($path, file_get_contents($path).'tamper');

    Config::set('database.connections.mysql.username', 'gestion_app');

    expect(fn () => app(DatabaseRestoreService::class)->restore($name, 'gestion_recovery', 'RESTORE'))
        ->toThrow(RuntimeException::class);

    expect($recording->runCount)->toBe(0);
});

test('safety backup failure prevents restore delegation in BackupController', function () {
    $source = file_get_contents(app_path('Http/Controllers/Admin/BackupController.php'));

    expect($source)->toContain('safety_backup_failed');
    expect($source)->toContain('La sauvegarde de sécurité a échoué');
    expect(strpos($source, 'if ($exitCode !== 0)'))->toBeLessThan(strpos($source, '$restore->restore('));
});

test('PrivilegedRestoreProcessRunner defines a subprocess timeout', function () {
    expect(PrivilegedRestoreProcessRunner::DEFAULT_TIMEOUT_SECONDS)->toBe(1800);
});
