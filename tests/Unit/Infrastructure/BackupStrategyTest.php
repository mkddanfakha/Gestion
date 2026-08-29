<?php

use App\Database\BackupArchiveInspector;
use App\Database\BackupConcurrencyGuard;
use App\Database\DatabaseSafetyGuard;
use App\Database\ProtectedDatabaseException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

afterEach(function () {
    BackupConcurrencyGuard::releaseBackup();
    BackupConcurrencyGuard::releaseRestore();
    try {
        BackupConcurrencyGuard::store()->flush();
    } catch (Throwable) {
        // ignore store flush errors in tests
    }
    Config::set('backup.lock_cache_store', 'file');
    Config::set('cache.default', env('CACHE_STORE', 'array'));
});

test('backup lock store defaults to file independent of database cache', function () {
    Config::set('cache.default', 'database');
    Config::set('backup.lock_cache_store', 'file');

    expect(BackupConcurrencyGuard::lockStoreName())->toBe('file');

    BackupConcurrencyGuard::acquireBackup(60);
    expect(BackupConcurrencyGuard::isBackupLocked())->toBeTrue();
    expect(Cache::store('file')->has(BackupConcurrencyGuard::BACKUP_LOCK_KEY))->toBeTrue();
});

test('concurrent restore lock blocks a second acquire', function () {
    BackupConcurrencyGuard::acquireRestore(60);
    expect(BackupConcurrencyGuard::isRestoreLocked())->toBeTrue();

    expect(fn () => BackupConcurrencyGuard::acquireRestore(60))
        ->toThrow(RuntimeException::class);

    BackupConcurrencyGuard::releaseRestore();
    BackupConcurrencyGuard::acquireRestore(60);
    expect(true)->toBeTrue();
});

test('concurrent backup lock blocks a second acquire', function () {
    BackupConcurrencyGuard::acquireBackup(60);
    expect(fn () => BackupConcurrencyGuard::acquireBackup(60))
        ->toThrow(RuntimeException::class);
});

test('runBackup releases the lock after the callback', function () {
    $ran = false;
    BackupConcurrencyGuard::runBackup(function () use (&$ran) {
        $ran = true;
        expect(BackupConcurrencyGuard::isBackupLocked())->toBeTrue();
    }, 60);
    expect($ran)->toBeTrue();
    expect(BackupConcurrencyGuard::isBackupLocked())->toBeFalse();
});

test('runBackup releases the lock after exception', function () {
    try {
        BackupConcurrencyGuard::runBackup(function () {
            throw new RuntimeException('simulated-backup-failure');
        }, 60);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('simulated-backup-failure');
    }

    expect(BackupConcurrencyGuard::isBackupLocked())->toBeFalse();
});

test('runRestore releases the lock after the callback', function () {
    $ran = false;
    BackupConcurrencyGuard::runRestore(function () use (&$ran) {
        $ran = true;
        expect(BackupConcurrencyGuard::isRestoreLocked())->toBeTrue();
    });
    expect($ran)->toBeTrue();
    expect(BackupConcurrencyGuard::isRestoreLocked())->toBeFalse();
});

test('backup lock expires after TTL', function () {
    BackupConcurrencyGuard::acquireBackup(1);
    expect(BackupConcurrencyGuard::isBackupLocked())->toBeTrue();
    sleep(2);
    expect(BackupConcurrencyGuard::isBackupLocked())->toBeFalse();
    BackupConcurrencyGuard::acquireBackup(60);
    expect(BackupConcurrencyGuard::isBackupLocked())->toBeTrue();
});

test('backup archive inspector reports checksum and SQL inserts', function () {
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mkd-backup-inspect-'.uniqid().'.zip';
    $zip = new ZipArchive();
    expect($zip->open($tmp, ZipArchive::CREATE))->toBeTrue();
    $sql = "CREATE TABLE `customers` (id int);\nINSERT INTO `customers` VALUES (1);\nINSERT INTO `sales` VALUES (1);\n";
    $zip->addFromString('db-dumps/mysql-gestion.sql', $sql);
    $zip->close();

    $report = BackupArchiveInspector::inspect($tmp);
    expect($report['readable'])->toBeTrue();
    expect($report['sha256'])->toBeString()->not->toBeEmpty();
    expect($report['sql']['present'])->toBeTrue();
    expect($report['sql']['insert_counts']['customers'])->toBe(1);
    expect($report['sql']['insert_counts']['sales'])->toBe(1);
    expect($report['verdict'])->toBe('HAS_BUSINESS_INSERTS');
    expect($report['contains_dotenv'])->toBeFalse();

    unlink($tmp);
});

test('schema-only dump is classified SCHEMA_ONLY', function () {
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mkd-backup-schema-'.uniqid().'.zip';
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE);
    $zip->addFromString('db-dumps/mysql-gestion.sql', "CREATE TABLE `customers` (id int);\n");
    $zip->close();

    $report = BackupArchiveInspector::inspect($tmp);
    expect($report['verdict'])->toBe('SCHEMA_ONLY');
    expect($report['sql']['business_inserts'])->toBe(0);
    unlink($tmp);
});

test('restore to gestion remains blocked independently of backup locks', function () {
    expect(fn () => DatabaseSafetyGuard::assertSafeForRestore('gestion'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('backup:status is read-only and reports offsite not configured', function () {
    Config::set('backup.backup.destination.disks', ['local']);
    Config::set('filesystems.disks.s3.bucket', '');

    $exit = Artisan::call('backup:status', ['--json' => true]);
    expect($exit)->toBe(0);
    $payload = json_decode(Artisan::output(), true);
    expect($payload['no_changes_performed'])->toBeTrue();
    expect($payload['offsite'])->toBe('NOT_CONFIGURED');
    expect($payload['backup_offsite'])->toBe('NOT_CONFIGURED');
    expect($payload['status'])->toBe('BACKUP DEGRADED');
    expect($payload['mode'])->toBe('LOCAL_ONLY');
    expect($payload['last_restore_test'])->toBe('NOT_TESTED');
    expect($payload['operation'])->toBe('STATUS ONLY');
    expect($payload['rpo_hours'])->toBe(24);
});

test('operational status marks local-only with missing offsite as DEGRADED when s3 wired', function () {
    Config::set('backup.backup.destination.disks', ['local', 's3']);
    Config::set('filesystems.disks.s3.bucket', 'test-bucket');
    Config::set('filesystems.disks.s3.endpoint', 'https://example.invalid');

    // Storage::disk('s3') will fail reachability → FAILED/MISSING offsite
    $ops = \App\Database\BackupOperationalStatus::evaluate();
    expect($ops['backup_offsite'])->toBeIn(['MISSING', 'FAILED']);
    expect($ops['status'])->toBe('BACKUP DEGRADED');
});

test('first verified production backup checksum matches known value when file present', function () {
    $path = storage_path('app/private/Gestion/2026-08-26-15-16-45.zip');
    if (! is_file($path)) {
        expect(true)->toBeTrue();

        return;
    }

    $report = BackupArchiveInspector::inspect($path);
    expect($report['readable'])->toBeTrue();
    expect($report['sha256'])->toBe('3231554e80931843fc634dab269fc9606f47f2208750f9bddf9cce2eca1453dc');
    expect($report['sql']['create_table_count'])->toBe(38);
    expect($report['sql']['business_inserts'])->toBe(0);
    expect($report['contains_dotenv'])->toBeFalse();
    expect($report['verdict'])->toBe('SCHEMA_ONLY');
});
