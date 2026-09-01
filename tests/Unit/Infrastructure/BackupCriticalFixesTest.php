<?php

use App\Database\BackupConcurrencyGuard;
use App\Jobs\CreateBackupJob;
use App\Services\Backup\BackupImportService;
use App\Services\Backup\BackupMetadataService;
use App\Services\Backup\BackupPathGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('backup.backup.name', 'mkdpro-test-backups');
    Config::set('backup.backup.destination.disks', ['local']);
    Config::set('filesystems.disks.local.root', storage_path('app/private'));
    Config::set('backup.lock_cache_store', 'array');
    Config::set('cache.stores.array', ['driver' => 'array']);

    BackupConcurrencyGuard::releaseBackup();
    BackupConcurrencyGuard::releaseRestore();

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
        // ignore
    }

    $quarantine = storage_path('app/'.BackupImportService::QUARANTINE_DIR);
    if (is_dir($quarantine)) {
        foreach (glob($quarantine.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
    }
});

function makeMinimalValidBackupZip(string $absolutePath): void
{
    $zip = new ZipArchive();
    expect($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString(
        'db-dumps/mysql-gestion.sql',
        "CREATE TABLE `users` (`id` int);\nINSERT INTO `users` VALUES (1);\n",
    );
    $zip->close();
}

test('path guard rejects traversal and absolute backup names', function () {
    expect(fn () => BackupPathGuard::sanitizeBackupFileName('../etc/passwd.zip'))
        ->toThrow(RuntimeException::class);

    expect(fn () => BackupPathGuard::sanitizeBackupFileName('..\\secret.zip'))
        ->toThrow(RuntimeException::class);

    expect(fn () => BackupPathGuard::sanitizeBackupFileName('/etc/passwd.zip'))
        ->toThrow(RuntimeException::class);

    expect(fn () => BackupPathGuard::sanitizeBackupFileName('C:\\Windows\\file.zip'))
        ->toThrow(RuntimeException::class);

    expect(fn () => BackupPathGuard::sanitizeBackupFileName('not-a-zip.txt'))
        ->toThrow(RuntimeException::class);
});

test('path guard accepts simple zip names and resolves inside backup dir', function () {
    $name = BackupPathGuard::sanitizeBackupFileName('folder/ignored/2026-08-30-120000.zip');
    expect($name)->toBe('2026-08-30-120000.zip');

    $path = BackupPathGuard::resolveNewBackupPath($name);
    makeMinimalValidBackupZip($path);

    $resolved = BackupPathGuard::resolveExistingBackupPath($name);
    expect($resolved)->toBe(BackupPathGuard::normalizeSeparators($path));
    expect(BackupPathGuard::isPathInsideDirectory(
        $resolved,
        BackupPathGuard::backupDirectoryAbsolutePath(),
    ))->toBeTrue();
});

test('path guard rejects resolve when file would escape directory', function () {
    expect(fn () => BackupPathGuard::resolveExistingBackupPath('../../.env.zip'))
        ->toThrow(RuntimeException::class);

    expect(fn () => BackupPathGuard::resolveExistingBackupPath('missing-file.zip'))
        ->toThrow(RuntimeException::class);
});

test('zip entry path safety rejects traversal absolute and symlink mode', function () {
    expect(fn () => BackupPathGuard::assertSafeZipEntryName('../evil.sql'))
        ->toThrow(RuntimeException::class);

    expect(fn () => BackupPathGuard::assertSafeZipEntryName('/etc/passwd'))
        ->toThrow(RuntimeException::class);

    expect(fn () => BackupPathGuard::assertSafeZipEntryName('C:/Windows/system32'))
        ->toThrow(RuntimeException::class);

    BackupPathGuard::assertSafeZipEntryName('db-dumps/mysql-gestion.sql');
    expect(true)->toBeTrue();
});

test('import service accepts valid zip and does not restore', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'bk');
    @unlink($tmp);
    $tmpZip = $tmp.'.zip';
    makeMinimalValidBackupZip($tmpZip);

    $upload = new UploadedFile($tmpZip, 'demo-backup.zip', 'application/zip', null, true);
    $result = (new BackupImportService)->import($upload);

    expect($result['filename'])->toBe('demo-backup.zip');
    expect(is_file(BackupPathGuard::resolveExistingBackupPath($result['filename'])))->toBeTrue();
    expect($result['inspection']['readable'] ?? false)->toBeTrue();
    expect($result['status'] ?? null)->toBe(BackupMetadataService::STATUS_IMPORTED);
    expect($result['type'] ?? null)->toBe(BackupMetadataService::TYPE_DATABASE);

    $meta = BackupMetadataService::readForZip($result['filename']);
    expect($meta)->not->toBeNull();
    expect($meta['source'] ?? null)->toBe(BackupMetadataService::SOURCE_IMPORT);
    expect($meta['status'] ?? null)->toBe(BackupMetadataService::STATUS_IMPORTED);
    expect($meta['sha256'] ?? null)->not->toBeEmpty();
    expect($meta['backup_format_version'] ?? null)->toBe(BackupMetadataService::FORMAT_VERSION);

    // Import must never call restore locks as a side effect of success path
    expect(BackupConcurrencyGuard::isRestoreLocked())->toBeFalse();

    @unlink($tmpZip);
});

test('import of full archive writes FULL type in sidecar', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'bkfull');
    @unlink($tmp);
    $tmpZip = $tmp.'.zip';
    $zip = new ZipArchive();
    expect($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('db-dumps/mysql-gestion.sql', "CREATE TABLE `users` (`id` int);\nINSERT INTO `users` VALUES (1);\n");
    $zip->addFromString('attachments/demo/file.txt', 'hello');
    $zip->close();

    $upload = new UploadedFile($tmpZip, 'full-backup.zip', 'application/zip', null, true);
    $result = (new BackupImportService)->import($upload);

    expect($result['type'])->toBe(BackupMetadataService::TYPE_FULL);
    $meta = BackupMetadataService::readForZip($result['filename']);
    expect($meta['type'] ?? null)->toBe(BackupMetadataService::TYPE_FULL);

    @unlink($tmpZip);
});

test('metadata sidecar is deleted with helper and stays inside backup dir', function () {
    $name = 'meta-sample.zip';
    $path = BackupPathGuard::resolveNewBackupPath($name);
    makeMinimalValidBackupZip($path);

    $written = BackupMetadataService::writeForZip($name, [
        'type' => BackupMetadataService::TYPE_DATABASE,
        'source' => BackupMetadataService::SOURCE_IMPORT,
        'status' => BackupMetadataService::STATUS_IMPORTED,
        'sha256' => str_repeat('a', 64),
        'size_bytes' => 123,
    ]);

    expect($written['filename'])->toBe($name);
    expect(BackupMetadataService::readForZip($name))->not->toBeNull();

    $metaPath = BackupMetadataService::resolveMetaPathForZip($name);
    expect(BackupPathGuard::isPathInsideDirectory(
        dirname($metaPath),
        BackupPathGuard::backupDirectoryAbsolutePath(),
    ))->toBeTrue();

    BackupMetadataService::deleteForZip($name);
    expect(BackupMetadataService::readForZip($name))->toBeNull();
});

test('import service rejects corrupted zip', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'bkbad');
    file_put_contents($tmp, 'not-a-zip');
    $upload = new UploadedFile($tmp, 'bad.zip', 'application/zip', null, true);

    expect(fn () => (new BackupImportService)->import($upload))
        ->toThrow(RuntimeException::class);

    @unlink($tmp);
});

test('import service rejects empty zip', function () {
    $tmpZip = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bkempty-'.uniqid('', true).'.zip';
    // Empty ZIP EOCD (ZipArchive often deletes empty CREATE archives on Windows close).
    file_put_contents($tmpZip, hex2bin('504b0506000000000000000000000000000000000000'));
    expect(is_file($tmpZip))->toBeTrue();

    $upload = new UploadedFile($tmpZip, 'empty.zip', 'application/zip', null, true);

    expect(fn () => (new BackupImportService)->import($upload))
        ->toThrow(RuntimeException::class);

    @unlink($tmpZip);
});

test('assertZipReadableAndSafe rejects zero-entry archive', function () {
    $tmpZip = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bkzero-'.uniqid('', true).'.zip';
    file_put_contents($tmpZip, hex2bin('504b0506000000000000000000000000000000000000'));

    expect(fn () => (new BackupImportService)->assertZipReadableAndSafe($tmpZip))
        ->toThrow(RuntimeException::class);

    @unlink($tmpZip);
});

test('import service rejects zip without sql dump', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'bknosql');
    @unlink($tmp);
    $tmpZip = $tmp.'.zip';
    $zip = new ZipArchive();
    $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('readme.txt', 'no dump here');
    $zip->close();

    $upload = new UploadedFile($tmpZip, 'nosql.zip', 'application/zip', null, true);

    expect(fn () => (new BackupImportService)->import($upload))
        ->toThrow(RuntimeException::class);

    @unlink($tmpZip);
});

test('import service rejects zip with traversal entry', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'bktrav');
    @unlink($tmp);
    $tmpZip = $tmp.'.zip';
    $zip = new ZipArchive();
    $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('../evil.sql', 'CREATE TABLE x (id int);');
    $zip->close();

    $upload = new UploadedFile($tmpZip, 'trav.zip', 'application/zip', null, true);

    expect(fn () => (new BackupImportService)->import($upload))
        ->toThrow(RuntimeException::class);

    @unlink($tmpZip);
});

test('import service rejects non-zip extension', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'bkext');
    file_put_contents($tmp, 'data');
    $upload = new UploadedFile($tmp, 'file.txt', 'text/plain', null, true);

    expect(fn () => (new BackupImportService)->import($upload))
        ->toThrow(RuntimeException::class);

    @unlink($tmp);
});

test('controller store does not nest BackupConcurrencyGuard around backup:production', function () {
    $source = file_get_contents(app_path('Http/Controllers/Admin/BackupController.php'));

    expect($source)->not->toContain('BackupConcurrencyGuard::runBackup');
    expect($source)->toContain('BackupCreationService');
    expect($source)->not->toContain('sleep(');
});

test('CreateBackupJob does not acquire concurrency lock itself', function () {
    $source = file_get_contents(app_path('Jobs/CreateBackupJob.php'));

    expect($source)->not->toContain('BackupConcurrencyGuard::');
    expect($source)->not->toContain('runBackup(');
    expect($source)->toContain("Artisan::call('backup:production'");
});

test('creation service dispatches CreateBackupJob with progress key', function () {
    Illuminate\Support\Facades\Bus::fake();

    $user = new App\Models\User;
    $user->id = 42;
    $user->exists = true;

    $result = (new App\Services\Backup\BackupCreationService)->start(true, $user);

    expect($result['job_id'])->not->toBeEmpty();
    expect($result['only_db'])->toBeTrue();
    expect($result['status'])->toBe('queued');

    Illuminate\Support\Facades\Bus::assertDispatched(CreateBackupJob::class, function (CreateBackupJob $job) use ($result) {
        return $job->onlyDb === true
            && $job->userId === 42
            && $job->jobId === $result['job_id'];
    });

    $progress = App\Services\Backup\BackupCreationProgress::get($result['job_id']);
    expect($progress)->not->toBeNull();
    expect($progress['status'] ?? null)->toBe('queued');
    expect($progress['user_id'] ?? null)->toBe(42);
});

test('CreateBackupJob writes manual sidecar after successful backup:production', function () {
    $acquisitions = 0;

    $fakeRunner = new class($acquisitions) extends \App\Database\PrivilegedProcessRunner
    {
        public function __construct(private int &$acquisitions) {}

        public function runBackupRun(bool $onlyDb = false, ?array $credentials = null): \App\Database\PrivilegedProcessResult
        {
            expect(BackupConcurrencyGuard::isBackupLocked())->toBeTrue();
            $this->acquisitions++;

            $path = BackupPathGuard::resolveNewBackupPath('job-created-'.uniqid('', true).'.zip');
            makeMinimalValidBackupZip($path);

            return new \App\Database\PrivilegedProcessResult(0, 'ok', '');
        }
    };

    app()->instance(\App\Database\PrivilegedProcessRunner::class, $fakeRunner);

    $jobId = 'test-job-'.uniqid();
    $job = new CreateBackupJob(true, 7, $jobId);
    $job->handle(app(App\Services\Backup\BackupCreationService::class));

    expect($acquisitions)->toBe(1);

    $progress = App\Services\Backup\BackupCreationProgress::get($jobId);
    expect($progress['status'] ?? null)->toBe('completed');
    expect($progress['percentage'] ?? null)->toBe(100);

    $filename = $progress['filename'] ?? null;
    expect($filename)->not->toBeEmpty();
    $meta = BackupMetadataService::readForZip((string) $filename);
    expect($meta['source'] ?? null)->toBe(BackupMetadataService::SOURCE_MANUAL);
    expect($meta['type'] ?? null)->toBe(BackupMetadataService::TYPE_DATABASE);
    expect($meta['status'] ?? null)->toBe(BackupMetadataService::STATUS_VALID);
});

test('inspect endpoint data is path-safe and does not restore', function () {
    $name = 'inspect-demo.zip';
    $path = BackupPathGuard::resolveNewBackupPath($name);
    makeMinimalValidBackupZip($path);

    $absolute = BackupPathGuard::resolveExistingBackupPath($name);
    $inspection = App\Database\BackupArchiveInspector::inspect($absolute);

    expect($inspection['readable'] ?? false)->toBeTrue();
    expect($inspection['sql']['present'] ?? false)->toBeTrue();
    expect(BackupConcurrencyGuard::isRestoreLocked())->toBeFalse();
});

test('controller restore requires acknowledge_overwrite and keeps allow-list guards', function () {
    $source = file_get_contents(app_path('Http/Controllers/Admin/BackupController.php'));

    expect($source)->toContain('acknowledge_overwrite');
    expect($source)->toContain('safety_backup');
    expect($source)->toContain('function inspect');
    expect($source)->toContain('BackupArchiveInspector::inspect');
    expect($source)->toContain("Artisan::call('backup:production'");
    expect($source)->not->toContain('BackupConcurrencyGuard::runBackup');
});

test('ui creation path acquires lock only once via backup:production', function () {
    $acquisitions = 0;

    $fakeRunner = new class($acquisitions) extends \App\Database\PrivilegedProcessRunner
    {
        public function __construct(private int &$acquisitions) {}

        public function runBackupRun(bool $onlyDb = false, ?array $credentials = null): \App\Database\PrivilegedProcessResult
        {
            expect(BackupConcurrencyGuard::isBackupLocked())->toBeTrue();
            $this->acquisitions++;

            return new \App\Database\PrivilegedProcessResult(0, 'ok', '');
        }
    };

    // Bind fake runner; production command still wraps with a single runBackup.
    app()->instance(\App\Database\PrivilegedProcessRunner::class, $fakeRunner);

    expect(BackupConcurrencyGuard::isBackupLocked())->toBeFalse();
    $exit = Artisan::call('backup:production', ['--only-db' => true]);
    expect($exit)->toBe(0);
    expect($acquisitions)->toBe(1);
    expect(BackupConcurrencyGuard::isBackupLocked())->toBeFalse();
});

test('concurrent second backup:production fails while lock held then succeeds after release', function () {
    BackupConcurrencyGuard::acquireBackup(60);

    $exitWhileLocked = Artisan::call('backup:production');
    expect($exitWhileLocked)->not->toBe(0);
    expect(Artisan::output())->toContain('already in progress');

    BackupConcurrencyGuard::releaseBackup();

    $fakeRunner = new class extends \App\Database\PrivilegedProcessRunner
    {
        public function __construct() {}

        public function runBackupRun(bool $onlyDb = false, ?array $credentials = null): \App\Database\PrivilegedProcessResult
        {
            return new \App\Database\PrivilegedProcessResult(0, 'second-ok', '');
        }
    };
    app()->instance(\App\Database\PrivilegedProcessRunner::class, $fakeRunner);

    expect(Artisan::call('backup:production'))->toBe(0);
    expect(BackupConcurrencyGuard::isBackupLocked())->toBeFalse();
});

test('backup:production releases lock after simulated failure', function () {
    $fakeRunner = new class extends \App\Database\PrivilegedProcessRunner
    {
        public function __construct() {}

        public function runBackupRun(bool $onlyDb = false, ?array $credentials = null): \App\Database\PrivilegedProcessResult
        {
            return new \App\Database\PrivilegedProcessResult(1, '', 'failed');
        }
    };
    app()->instance(\App\Database\PrivilegedProcessRunner::class, $fakeRunner);

    expect(Artisan::call('backup:production'))->toBe(1);
    expect(BackupConcurrencyGuard::isBackupLocked())->toBeFalse();
});
