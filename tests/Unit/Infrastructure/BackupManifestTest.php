<?php

use App\Services\Backup\BackupImportService;
use App\Services\Backup\BackupManifest;
use App\Services\Backup\BackupManifestService;
use App\Services\Backup\BackupMetadataService;
use App\Services\Backup\BackupPathGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('backup.backup.name', 'mkdpro-test-backups');
    Config::set('backup.backup.destination.disks', ['local']);
    Config::set('filesystems.disks.local.root', storage_path('app/private'));

    $dir = BackupPathGuard::backupDirectoryAbsolutePath();
    foreach (glob($dir.DIRECTORY_SEPARATOR.'*.zip') ?: [] as $file) {
        @unlink($file);
    }
    $metaDir = $dir.DIRECTORY_SEPARATOR.'meta';
    if (is_dir($metaDir)) {
        foreach (glob($metaDir.DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            @unlink($file);
        }
    }
});

afterEach(function () {
    try {
        $dir = BackupPathGuard::backupDirectoryAbsolutePath();
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.zip') ?: [] as $file) {
            @unlink($file);
        }
        $metaDir = $dir.DIRECTORY_SEPARATOR.'meta';
        if (is_dir($metaDir)) {
            foreach (glob($metaDir.DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
                @unlink($file);
            }
        }
    } catch (Throwable) {
    }

    $quarantine = storage_path('app/'.BackupImportService::QUARANTINE_DIR);
    if (is_dir($quarantine)) {
        foreach (glob($quarantine.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
    }
});

function makeManifestTestZip(string $absolutePath, bool $withFiles = false): void
{
    $zip = new ZipArchive();
    expect($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString(
        'db-dumps/mysql-gestion.sql',
        "CREATE TABLE `users` (`id` int);\nINSERT INTO `users` VALUES (1);\n",
    );
    if ($withFiles) {
        $zip->addFromString('attachments/demo/file.txt', 'hello');
    }
    $zip->close();
}

test('manifest is created for new backup with version type source sha256 and size', function () {
    $name = 'manifest-db.zip';
    $path = BackupPathGuard::resolveNewBackupPath($name);
    makeManifestTestZip($path, false);

    $manifest = app(BackupManifestService::class)->createAndWriteForExistingZip($name, [
        'type' => BackupMetadataService::TYPE_DATABASE,
        'source' => BackupMetadataService::SOURCE_MANUAL,
        'status' => BackupMetadataService::STATUS_VALID,
        'files_included' => false,
    ]);

    expect($manifest['manifest_version'])->toBe(BackupManifest::VERSION);
    expect($manifest['type'])->toBe(BackupMetadataService::TYPE_DATABASE);
    expect($manifest['source'])->toBe(BackupMetadataService::SOURCE_MANUAL);
    expect($manifest['files']['included'])->toBeFalse();
    expect($manifest['database']['included'])->toBeTrue();
    expect($manifest['archive']['sha256'])->toMatch('/^[a-f0-9]{64}$/');
    expect($manifest['archive']['size_bytes'])->toBe((int) filesize($path));
    expect(json_encode($manifest))->not->toContain('password');
    expect(json_encode($manifest))->not->toContain('APP_KEY');

    $read = app(BackupManifestService::class)->read($name);
    expect($read)->not->toBeNull();
    expect($read['manifest_version'])->toBe(1);
});

test('manifest type FULL when files included', function () {
    $name = 'manifest-full.zip';
    $path = BackupPathGuard::resolveNewBackupPath($name);
    makeManifestTestZip($path, true);

    $manifest = app(BackupManifestService::class)->createAndWriteForExistingZip($name, [
        'type' => BackupMetadataService::TYPE_FULL,
        'source' => BackupMetadataService::SOURCE_MANUAL,
        'files_included' => true,
    ]);

    expect($manifest['type'])->toBe(BackupMetadataService::TYPE_FULL);
    expect($manifest['files']['included'])->toBeTrue();
});

test('integrity VALID for matching hash and INVALID when file tampered', function () {
    $name = 'manifest-integrity.zip';
    $path = BackupPathGuard::resolveNewBackupPath($name);
    makeManifestTestZip($path);

    app(BackupManifestService::class)->createAndWriteForExistingZip($name, [
        'type' => BackupMetadataService::TYPE_DATABASE,
        'source' => BackupMetadataService::SOURCE_MANUAL,
    ]);

    $ok = app(BackupManifestService::class)->verifyIntegrity($name);
    expect($ok['result'])->toBe(BackupManifest::INTEGRITY_VALID);

    file_put_contents($path, file_get_contents($path).'tamper');

    $bad = app(BackupManifestService::class)->verifyIntegrity($name);
    expect($bad['result'])->toBe(BackupManifest::INTEGRITY_INVALID);
});

test('integrity MISSING when zip deleted and MANIFEST_INVALID when sidecar garbage', function () {
    $name = 'manifest-missing.zip';
    $path = BackupPathGuard::resolveNewBackupPath($name);
    makeManifestTestZip($path);
    app(BackupManifestService::class)->createAndWriteForExistingZip($name, [
        'type' => BackupMetadataService::TYPE_DATABASE,
        'source' => BackupMetadataService::SOURCE_MANUAL,
    ]);

    @unlink($path);
    $missing = app(BackupManifestService::class)->verifyIntegrity($name);
    expect($missing['result'])->toBe(BackupManifest::INTEGRITY_MISSING);

    $name2 = 'manifest-badmeta.zip';
    $path2 = BackupPathGuard::resolveNewBackupPath($name2);
    makeManifestTestZip($path2);
    $metaPath = BackupMetadataService::resolveMetaPathForZip($name2);
    file_put_contents($metaPath, '{not-json');

    $invalid = app(BackupManifestService::class)->verifyIntegrity($name2);
    expect($invalid['result'])->toBe(BackupManifest::INTEGRITY_MANIFEST_INVALID);
});

test('legacy sidecar without manifest_version is readable as review compatibility', function () {
    $name = 'legacy-meta.zip';
    $path = BackupPathGuard::resolveNewBackupPath($name);
    makeManifestTestZip($path);
    $hash = hash_file('sha256', $path);

    $metaPath = BackupMetadataService::resolveMetaPathForZip($name);
    file_put_contents($metaPath, json_encode([
        'backup_id' => 'legacy-1',
        'application' => 'MKD-Pro',
        'backup_format_version' => 1,
        'created_at' => now()->toIso8601String(),
        'type' => 'DATABASE',
        'source' => 'manual',
        'sha256' => $hash,
        'size_bytes' => filesize($path),
        'status' => 'valid',
        'filename' => $name,
    ], JSON_THROW_ON_ERROR));

    $hint = app(BackupManifestService::class)->listingIntegrityHint($name);
    expect($hint['integrity'])->toBe('manifest_present');
    expect($hint['compatibility'])->toBe(BackupManifest::COMPAT_REVIEW);

    $verify = app(BackupManifestService::class)->verifyIntegrity($name);
    expect($verify['result'])->toBe(BackupManifest::INTEGRITY_VALID);
});

test('import writes server manifest and ignores caller-provided hash', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'bkman');
    @unlink($tmp);
    $tmpZip = $tmp.'.zip';
    makeManifestTestZip($tmpZip);

    $upload = new UploadedFile($tmpZip, 'imported-manifest.zip', 'application/zip', null, true);
    $result = (new BackupImportService)->import($upload);

    expect($result['meta']['manifest_version'] ?? null)->toBe(1);
    expect($result['meta']['source'] ?? null)->toBe(BackupMetadataService::SOURCE_IMPORT);
    expect($result['sha256'])->toBe(hash_file('sha256', BackupPathGuard::resolveExistingBackupPath($result['filename'])));

    $verify = app(BackupManifestService::class)->verifyIntegrity($result['filename']);
    expect($verify['result'])->toBe(BackupManifest::INTEGRITY_VALID);

    @unlink($tmpZip);
});

test('manifest assertNoSecrets rejects password-like payload', function () {
    expect(fn () => BackupManifest::assertNoSecrets([
        'manifest_version' => 1,
        'db_password' => 'secret-value',
    ]))->toThrow(RuntimeException::class);
});

test('path guard remains required for integrity verification', function () {
    expect(fn () => app(BackupManifestService::class)->verifyIntegrity('../evil.zip'))
        ->toThrow(RuntimeException::class);
});
