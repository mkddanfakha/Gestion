<?php

use App\Console\Commands\RunProductionBackupCommand;
use App\Database\BackupConcurrencyGuard;
use App\Database\PrivilegedProcessResult;
use App\Database\PrivilegedProcessRunner;
use App\Jobs\CreateBackupJob;
use App\Services\Backup\BackupArchiveResolutionException;
use App\Services\Backup\BackupManifestAttachmentService;
use App\Services\Backup\BackupManifestService;
use App\Services\Backup\BackupMetadataService;
use App\Services\Backup\BackupPathGuard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('backup.backup.name', 'gestion/archive-resolution-test');
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
    BackupConcurrencyGuard::releaseBackup();
    BackupConcurrencyGuard::releaseRestore();

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
});

function resolutionMakeZip(string $filename, string $sql = "CREATE TABLE t (id int);\n"): string
{
    $path = BackupPathGuard::resolveNewBackupPath($filename);
    $zip = new ZipArchive;
    expect($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('db-dumps/mysql-gestion.sql', $sql);
    $zip->close();

    return $path;
}

test('resolveArchiveProducedByOperation ignores multiple unchanged pre-existing archives', function () {
    $service = app(BackupManifestAttachmentService::class);
    resolutionMakeZip('legacy-a.zip');
    resolutionMakeZip('legacy-b.zip');
    resolutionMakeZip('legacy-c.zip');
    $baseline = $service->snapshotExistingArchives();
    expect($baseline)->toHaveCount(3);

    $started = time();
    resolutionMakeZip('single-new-output.zip');

    $resolved = $service->resolveArchiveProducedByOperation($started, $baseline);
    expect($resolved['filename'] ?? null)->toBe('single-new-output.zip');
});

test('resolveArchiveProducedByOperation treats same mtime with different size as changed archive', function () {
    $service = app(BackupManifestAttachmentService::class);
    $path = resolutionMakeZip('same-mtime.zip');
    $baseline = $service->snapshotExistingArchives();
    $started = time();
    $mtime = filemtime($path);
    expect($mtime)->toBeInt();

    file_put_contents($path, str_repeat('x', 4096), FILE_APPEND);
    touch($path, $mtime);

    $resolved = $service->resolveArchiveProducedByOperation($started, $baseline);
    expect($resolved['filename'] ?? null)->toBe('same-mtime.zip');
});

test('resolveArchiveProducedByOperation ignores pre-existing archive when a new zip is created', function () {
    $service = app(BackupManifestAttachmentService::class);
    resolutionMakeZip('legacy-before-run.zip');
    $baseline = $service->snapshotExistingArchives();

    $started = time();
    resolutionMakeZip('operation-output.zip');

    $resolved = $service->resolveArchiveProducedByOperation($started, $baseline);
    expect($resolved)->not->toBeNull();
    expect($resolved['filename'])->toBe('operation-output.zip');
});

test('resolveArchiveProducedByOperation returns null when subprocess produces no new archive', function () {
    $service = app(BackupManifestAttachmentService::class);
    resolutionMakeZip('only-legacy.zip');
    $baseline = $service->snapshotExistingArchives();

    expect($service->resolveArchiveProducedByOperation(time(), $baseline))->toBeNull();
});

test('resolveArchiveProducedByOperation throws when multiple new archives match', function () {
    $service = app(BackupManifestAttachmentService::class);
    $baseline = $service->snapshotExistingArchives();
    $started = time();

    resolutionMakeZip('multi-a.zip');
    resolutionMakeZip('multi-b.zip');

    expect(fn () => $service->resolveArchiveProducedByOperation($started, $baseline))
        ->toThrow(BackupArchiveResolutionException::class);
});

test('resolveArchiveProducedByOperation picks the archive modified in place when baseline differs', function () {
    $service = app(BackupManifestAttachmentService::class);
    $path = resolutionMakeZip('in-place.zip');
    $baseline = $service->snapshotExistingArchives();
    expect($baseline)->toHaveKey('in-place.zip');

    $started = time();
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('db-dumps/mysql-gestion.sql', "CREATE TABLE t2 (id int);\n");
    $zip->close();
    touch($path, $started + 1);

    $resolved = $service->resolveArchiveProducedByOperation($started, $baseline);
    expect($resolved['filename'] ?? null)->toBe('in-place.zip');
});

test('attachArchiveProducedByOperation writes manifest with matching sha256', function () {
    $service = app(BackupManifestAttachmentService::class);
    resolutionMakeZip('old-neighbor.zip');
    $baseline = $service->snapshotExistingArchives();
    $started = time() - 2;
    $path = resolutionMakeZip('sha-target.zip');

    $meta = $service->attachArchiveProducedByOperation(
        $started,
        $baseline,
        true,
        BackupMetadataService::SOURCE_SCHEDULER,
        null,
    );

    expect($meta['filename'] ?? null)->toBe('sha-target.zip');
    expect($meta['archive']['sha256'] ?? null)->toBe(hash_file('sha256', $path));

    $verify = app(BackupManifestService::class)->verifyIntegrity('sha-target.zip');
    expect($verify['result'])->toBe(\App\Services\Backup\BackupManifest::INTEGRITY_VALID);
});

test('backup production scheduled path attaches manifest only to operation archive', function () {
    resolutionMakeZip('preexisting-sched.zip');

    $fakeRunner = new class extends PrivilegedProcessRunner
    {
        public function __construct() {}

        public function runBackupRun(bool $onlyDb = false, ?array $credentials = null, ?string $onlyToDisk = null): PrivilegedProcessResult
        {
            resolutionMakeZip('cron-new-'.uniqid('', true).'.zip');

            return new PrivilegedProcessResult(0, 'ok', '');
        }
    };

    app()->instance(PrivilegedProcessRunner::class, $fakeRunner);

    expect(Artisan::call('backup:production', ['--only-db' => true]))->toBe(0);

    $dir = BackupPathGuard::backupDirectoryAbsolutePath();
    $newZips = array_values(array_filter(
        glob($dir.DIRECTORY_SEPARATOR.'cron-new-*.zip') ?: [],
        static fn (string $path): bool => BackupMetadataService::readForZip(basename($path)) !== null,
    ));
    expect($newZips)->toHaveCount(1);

    expect(BackupMetadataService::readForZip('preexisting-sched.zip'))->toBeNull();
});

test('backup production keeps zip on disk when manifest attachment throws', function () {
    $zipName = 'manifest-fail-kept-'.uniqid('', true).'.zip';

    $fakeRunner = new class($zipName) extends PrivilegedProcessRunner
    {
        public function __construct(private string $zipName) {}

        public function runBackupRun(bool $onlyDb = false, ?array $credentials = null, ?string $onlyToDisk = null): PrivilegedProcessResult
        {
            resolutionMakeZip($this->zipName);

            return new PrivilegedProcessResult(0, 'ok', '');
        }
    };

    app()->instance(PrivilegedProcessRunner::class, $fakeRunner);

    $manifestPartial = Mockery::mock(app(BackupManifestService::class))->makePartial();
    $manifestPartial->shouldReceive('createAndWriteForExistingZip')
        ->once()
        ->andThrow(new RuntimeException('simulated manifest write failure'));
    app()->instance(BackupManifestService::class, $manifestPartial);

    expect(Artisan::call('backup:production', ['--only-db' => true]))->not->toBe(0);
    expect(is_file(BackupPathGuard::resolveExistingBackupPath($zipName)))->toBeTrue();
    expect(BackupMetadataService::readForZip($zipName))->toBeNull();
});

test('backup production returns failure when manifest attachment throws', function () {
    $fakeRunner = new class extends PrivilegedProcessRunner
    {
        public function __construct() {}

        public function runBackupRun(bool $onlyDb = false, ?array $credentials = null, ?string $onlyToDisk = null): PrivilegedProcessResult
        {
            resolutionMakeZip('manifest-fail-'.uniqid('', true).'.zip');

            return new PrivilegedProcessResult(0, 'ok', '');
        }
    };

    app()->instance(PrivilegedProcessRunner::class, $fakeRunner);

    $manifestPartial = Mockery::mock(app(BackupManifestService::class))->makePartial();
    $manifestPartial->shouldReceive('createAndWriteForExistingZip')
        ->once()
        ->andThrow(new RuntimeException('simulated manifest write failure'));
    app()->instance(BackupManifestService::class, $manifestPartial);

    expect(Artisan::call('backup:production', ['--only-db' => true]))->not->toBe(0);
});

test('backup production returns failure when no archive is produced', function () {
    $fakeRunner = new class extends PrivilegedProcessRunner
    {
        public function __construct() {}

        public function runBackupRun(bool $onlyDb = false, ?array $credentials = null, ?string $onlyToDisk = null): PrivilegedProcessResult
        {
            return new PrivilegedProcessResult(0, 'ok-no-zip', '');
        }
    };

    app()->instance(PrivilegedProcessRunner::class, $fakeRunner);

    expect(Artisan::call('backup:production', ['--only-db' => true]))->toBe(RunProductionBackupCommand::FAILURE);
});

test('backup production defer-manifest still skips sidecar with only-to-disk manual path unchanged', function () {
    $fakeRunner = new class extends PrivilegedProcessRunner
    {
        public function __construct() {}

        public function runBackupRun(bool $onlyDb = false, ?array $credentials = null, ?string $onlyToDisk = null): PrivilegedProcessResult
        {
            expect($onlyToDisk)->toBe('local');
            resolutionMakeZip('defer-local-'.uniqid('', true).'.zip');

            return new PrivilegedProcessResult(0, 'ok', '');
        }
    };

    app()->instance(PrivilegedProcessRunner::class, $fakeRunner);

    expect(Artisan::call('backup:production', [
        '--only-db' => true,
        '--only-to-disk' => 'local',
        '--defer-manifest' => true,
    ]))->toBe(0);

    $dir = BackupPathGuard::backupDirectoryAbsolutePath();
    foreach (glob($dir.DIRECTORY_SEPARATOR.'defer-local-*.zip') ?: [] as $file) {
        expect(BackupMetadataService::readForZip(basename($file)))->toBeNull();
    }
});

test('CreateBackupJob fails finalization without deleting produced zip', function () {
    $zipName = 'job-manifest-fail-'.uniqid('', true).'.zip';

    $fakeRunner = new class($zipName) extends PrivilegedProcessRunner
    {
        public function __construct(private string $zipName) {}

        public function runBackupRun(bool $onlyDb = false, ?array $credentials = null, ?string $onlyToDisk = null): PrivilegedProcessResult
        {
            resolutionMakeZip($this->zipName);

            return new PrivilegedProcessResult(0, 'ok', '');
        }
    };

    app()->instance(PrivilegedProcessRunner::class, $fakeRunner);

    $manifestPartial = Mockery::mock(app(BackupManifestService::class))->makePartial();
    $manifestPartial->shouldReceive('createAndWriteForExistingZip')
        ->once()
        ->andThrow(new RuntimeException('simulated manifest write failure'));
    app()->instance(BackupManifestService::class, $manifestPartial);

    $jobId = 'job-manifest-fail-'.uniqid();
    $job = new CreateBackupJob(true, 9, $jobId);

    expect(fn () => $job->handle(
        app(\App\Services\Backup\BackupCreationService::class),
        app(\App\Services\Backup\BackupJobOutputSanitizer::class),
    ))->toThrow(RuntimeException::class);

    $progress = \App\Services\Backup\BackupCreationProgress::get($jobId);
    expect($progress['status'] ?? null)->toBe('failed');
    expect($progress['failure_reason'] ?? null)->toBe('manifest_failed');
    expect(is_file(BackupPathGuard::resolveExistingBackupPath($zipName)))->toBeTrue();
});

test('CreateBackupJob manual path ignores older zip and attaches manifest to new archive', function () {
    resolutionMakeZip('older-manual.zip');

    $fakeRunner = new class extends PrivilegedProcessRunner
    {
        public function __construct() {}

        public function runBackupRun(bool $onlyDb = false, ?array $credentials = null, ?string $onlyToDisk = null): PrivilegedProcessResult
        {
            expect($onlyToDisk)->toBe('local');
            resolutionMakeZip('job-new-'.uniqid('', true).'.zip');

            return new PrivilegedProcessResult(0, 'ok', '');
        }
    };

    app()->instance(PrivilegedProcessRunner::class, $fakeRunner);

    $jobId = 'resolution-job-'.uniqid();
    $job = new CreateBackupJob(true, 9, $jobId);
    $job->handle(
        app(\App\Services\Backup\BackupCreationService::class),
        app(\App\Services\Backup\BackupJobOutputSanitizer::class),
    );

    $progress = \App\Services\Backup\BackupCreationProgress::get($jobId);
    expect($progress['status'] ?? null)->toBe('completed');
    $filename = (string) ($progress['filename'] ?? '');
    expect($filename)->toStartWith('job-new-');
    expect(BackupMetadataService::readForZip($filename)['source'] ?? null)
        ->toBe(BackupMetadataService::SOURCE_MANUAL);
});

test('concurrent backup lock still wraps production subprocess only once', function () {
    $acquisitions = 0;

    $fakeRunner = new class($acquisitions) extends PrivilegedProcessRunner
    {
        public function __construct(private int &$acquisitions) {}

        public function runBackupRun(bool $onlyDb = false, ?array $credentials = null, ?string $onlyToDisk = null): PrivilegedProcessResult
        {
            expect(BackupConcurrencyGuard::isBackupLocked())->toBeTrue();
            $this->acquisitions++;
            resolutionMakeZip('lock-once-'.uniqid('', true).'.zip');

            return new PrivilegedProcessResult(0, 'ok', '');
        }
    };

    app()->instance(PrivilegedProcessRunner::class, $fakeRunner);

    expect(BackupConcurrencyGuard::isBackupLocked())->toBeFalse();
    Artisan::call('backup:production', ['--only-db' => true]);
    expect($acquisitions)->toBe(1);
    expect(BackupConcurrencyGuard::isBackupLocked())->toBeFalse();
});
