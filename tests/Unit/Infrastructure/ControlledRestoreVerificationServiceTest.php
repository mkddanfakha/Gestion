<?php

use App\Console\Commands\RestoreVerifyProtocolCommand;
use App\Database\DatabaseAccountGuard;
use App\Database\DatabaseSafetyGuard;
use App\Database\PrivilegedCredentialLoader;
use App\Database\ProtectedDatabaseException;
use App\Services\Backup\BackupMetadataService;
use App\Services\Backup\BackupPathGuard;
use App\Services\Restore\ControlledRestoreSnapshotCollector;
use App\Services\Restore\ControlledRestoreSqlInspector;
use App\Services\Restore\ControlledRestoreVerificationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

function makeProtocolTestZip(string $filename, string $sql = "CREATE TABLE `users` (id int);\nINSERT INTO `users` VALUES (1);\n"): string
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
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', ':memory:');
    Config::set('database.connections.mysql.database', 'gestion');
    Config::set('database.connections.mysql.username', 'gestion_app');
    Config::set('database.connections.mysql.host', '127.0.0.1');
    Config::set('database.connections.mysql.port', '3306');
    Config::set('database-safety.protected_databases', ['gestion']);
    Config::set('database-safety.restore_allowed_databases', ['gestion_recovery', 'gestion_test']);
});

test('ControlledRestoreVerificationService blocks gestion target with FAIL', function () {
    $report = app(ControlledRestoreVerificationService::class)->runPreRestoreProtocol(null, 'gestion');

    expect($report['restore_executed'])->toBeFalse();
    expect($report['dry_run'])->toBeTrue();
    expect($report['status'])->toBe('FAIL');

    $targetCheck = collect($report['checks'])->firstWhere('check', 'target_explicit');
    expect($targetCheck['status'])->toBe('FAIL');
});

test('ControlledRestoreVerificationService default target is gestion_recovery', function () {
    expect(ControlledRestoreVerificationService::DEFAULT_TARGET)->toBe('gestion_recovery');

    $report = app(ControlledRestoreVerificationService::class)->runPreRestoreProtocol(null);

    expect($report['target'])->toBe('gestion_recovery');
    expect($report['restore_executed'])->toBeFalse();
});

test('ControlledRestoreVerificationService allow-list matrix matches policy', function () {
    $report = app(ControlledRestoreVerificationService::class)->runPreRestoreProtocol(null, 'gestion_recovery');

    expect($report['allow_list_matrix']['gestion'])->toBe('BLOCKED');
    expect($report['allow_list_matrix']['gestion_recovery'])->toBe('ALLOWED');
    expect($report['allow_list_matrix']['gestion_test'])->toBe('ALLOWED');
    expect($report['allow_list_matrix']['autre_base'])->toBe('BLOCKED');
    expect($report['allow_list_matrix']['gestion_recovery_x'])->toBe('BLOCKED');
    expect($report['allow_list_matrix']['gestion_test_x'])->toBe('BLOCKED');
});

test('ControlledRestoreVerificationService account separation matrix', function () {
    $report = app(ControlledRestoreVerificationService::class)->runPreRestoreProtocol(null, 'gestion_recovery');

    expect($report['accounts']['runtime_restore'])->toBe('DENIED');
    expect($report['accounts']['backup_restore'])->toBe('DENIED');
    expect($report['accounts']['restore_restore'])->toBe('ALLOWED');
});

test('ControlledRestoreVerificationService injection targets are blocked', function () {
    $report = app(ControlledRestoreVerificationService::class)->runPreRestoreProtocol(null, 'gestion_recovery');

    $injectionChecks = collect($report['checks'])->filter(
        fn (array $check): bool => str_starts_with($check['check'], 'injection:'),
    );

    expect($injectionChecks->count())->toBeGreaterThan(5);
    expect($injectionChecks->every(fn (array $check): bool => $check['status'] === 'PASS'))->toBeTrue();
});

test('ControlledRestoreSqlInspector detects dangerous gestion references', function () {
    $inspector = new ControlledRestoreSqlInspector;

    $safe = $inspector->inspectSqlContents("CREATE TABLE users (id int);\nINSERT INTO users VALUES (1);\n");
    expect($safe['status'])->toBe('PASS');

    $dangerous = $inspector->inspectSqlContents("USE gestion;\nCREATE TABLE users (id int);\n");
    expect($dangerous['status'])->toBe('FAIL');
    expect($dangerous['dangerous_gestion_references'])->not->toBeEmpty();
});

test('ControlledRestoreVerificationService verifies backup manifest and SQL when backup provided', function () {
    $name = 'protocol-backup.zip';
    makeProtocolTestZip($name);

    app(\App\Services\Backup\BackupManifestService::class)->createAndWriteForExistingZip($name, [
        'type' => BackupMetadataService::TYPE_DATABASE,
        'source' => BackupMetadataService::SOURCE_MANUAL,
    ]);

    $report = app(ControlledRestoreVerificationService::class)->runPreRestoreProtocol($name, 'gestion_recovery');

    expect($report['backup_details']['integrity']['result'] ?? null)->toBe('VALID');
    expect($report['sql_inspection']['sql_present'] ?? false)->toBeTrue();
    expect($report['sql_inspection']['status'])->toBe('PASS');

    $shaCheck = collect($report['checks'])->firstWhere('check', 'backup_manifest_sha256');
    expect($shaCheck['status'])->toBe('PASS');
});

test('ControlledRestoreVerificationService fails SHA check when backup tampered', function () {
    $name = 'protocol-tampered.zip';
    makeProtocolTestZip($name);

    app(\App\Services\Backup\BackupManifestService::class)->createAndWriteForExistingZip($name, [
        'type' => BackupMetadataService::TYPE_DATABASE,
        'source' => BackupMetadataService::SOURCE_MANUAL,
    ]);

    $path = BackupPathGuard::resolveExistingBackupPath($name);
    file_put_contents($path, file_get_contents($path).'tamper');

    $report = app(ControlledRestoreVerificationService::class)->runPreRestoreProtocol($name, 'gestion_recovery');

    $shaCheck = collect($report['checks'])->firstWhere('check', 'backup_manifest_sha256');
    expect($shaCheck['status'])->toBe('FAIL');
});

test('ControlledRestoreSnapshotCollector compareSnapshots detects differences', function () {
    $collector = new ControlledRestoreSnapshotCollector;

    $before = [
        'database' => 'gestion',
        'schema_fingerprint' => 'aaa',
        'row_counts' => ['users' => 10],
    ];
    $after = [
        'database' => 'gestion',
        'schema_fingerprint' => 'aaa',
        'row_counts' => ['users' => 11],
    ];

    $comparison = $collector->compareSnapshots($before, $after);
    expect($comparison['status'])->toBe('FAIL');
    expect($comparison['unchanged'])->toBeFalse();

    $same = $collector->compareSnapshots($before, $before);
    expect($same['status'])->toBe('PASS');
    expect($same['unchanged'])->toBeTrue();
});

test('ControlledRestoreVerificationService post-restore checks are implemented', function () {
    $before = app(ControlledRestoreVerificationService::class)->runPreRestoreProtocol(null, 'gestion_recovery');
    $post = app(ControlledRestoreVerificationService::class)->runPostRestoreChecks($before);

    expect($post['checks'])->not->toBeEmpty();
    expect(collect($post['checks'])->firstWhere('check', 'gestion_unchanged'))->not->toBeNull();
    expect(collect($post['checks'])->firstWhere('check', 'foreign_keys'))->not->toBeNull();
});

test('restore:verify-protocol command exists and refuses gestion target', function () {
    expect(class_exists(RestoreVerifyProtocolCommand::class))->toBeTrue();

    $exit = Artisan::call('restore:verify-protocol', ['--target' => 'gestion']);
    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('protected');
});

test('restore:verify-protocol command reports dry-run only for gestion_recovery', function () {
    $exit = Artisan::call('restore:verify-protocol', ['--target' => 'gestion_recovery']);
    $output = Artisan::output();

    expect($output)->toContain('DRY-RUN ONLY');
    expect($output)->toContain('NO RESTORE EXECUTED');
    expect($output)->not->toContain('DB_PASSWORD');
    expect($exit)->toBeIn([0, 1]);
});

test('restore:verify-protocol json output never contains password fields', function () {
    Artisan::call('restore:verify-protocol', [
        '--target' => 'gestion_recovery',
        '--json' => true,
    ]);

    $output = Artisan::output();
    expect($output)->not->toContain('DB_PASSWORD');
    expect($output)->toContain('"dry_run": true');
    expect($output)->toContain('"restore_executed": false');
});

test('credential report never exposes password', function () {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.PrivilegedCredentialLoader::RESTORE_CREDENTIAL_FILE;
    file_put_contents($path, implode("\n", [
        'DB_USERNAME=gestion_restore',
        'DB_PASSWORD=super-secret-restore-password',
        'DB_HOST=127.0.0.1',
        'DB_PORT=3306',
        'DB_DATABASE=gestion_recovery',
    ]));

    try {
        $report = app(ControlledRestoreVerificationService::class)->runPreRestoreProtocol(null, 'gestion_recovery');
        $encoded = json_encode($report);
        expect($encoded)->not->toContain('super-secret-restore-password');
        expect($encoded)->not->toContain('DB_PASSWORD');
    } finally {
        @unlink($path);
    }
});

test('gestion protection explicit check passes in protocol', function () {
    $report = app(ControlledRestoreVerificationService::class)->runPreRestoreProtocol(null, 'gestion_recovery');

    $check = collect($report['checks'])->firstWhere('check', 'gestion_protection_explicit');
    expect($check['status'])->toBe('PASS');
});

test('safety backup architecture check passes', function () {
    $report = app(ControlledRestoreVerificationService::class)->runPreRestoreProtocol(null, 'gestion_recovery');

    $check = collect($report['checks'])->firstWhere('check', 'safety_backup_architecture');
    expect($check['status'])->toBe('PASS');
});

test('DatabaseSafetyGuard blocks gestion_recovery_x and gestion_test_x', function () {
    foreach (['gestion_recovery_x', 'gestion_test_x', 'autre_base'] as $target) {
        expect(fn () => DatabaseSafetyGuard::assertExplicitRestoreTarget($target))
            ->toThrow(ProtectedDatabaseException::class);
    }
});

test('runtime gestion_app cannot perform restore operation directly', function () {
    expect(fn () => DatabaseAccountGuard::assertAccountForOperation(
        DatabaseAccountGuard::OPERATION_RESTORE,
        DatabaseAccountGuard::runtimeAccountName(),
    ))->toThrow(ProtectedDatabaseException::class);
});
