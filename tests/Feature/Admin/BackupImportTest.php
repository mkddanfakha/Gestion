<?php

use App\Models\User;
use App\Services\Backup\BackupManifest;
use App\Services\Backup\BackupManifestService;
use App\Services\Backup\BackupMetadataService;
use App\Services\Backup\BackupPathGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    $this->withoutVite();
    Config::set('backup.backup.name', 'gestion/feature-import');
    Config::set('backup.backup.destination.disks', ['local']);
    Config::set('filesystems.disks.local.root', storage_path('app/private'));
});

function featureMakeValidBackupZip(string $absolutePath): void
{
    $zip = new \ZipArchive;
    expect($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString(
        'db-dumps/mysql-gestion.sql',
        "CREATE TABLE `users` (`id` int);\nINSERT INTO `users` VALUES (1);\n",
    );
    $zip->close();
}

test('POST admin backups import restores max_execution_time after successful import', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $maxExecutionBefore = ini_get('max_execution_time');

    $tmp = tempnam(sys_get_temp_dir(), 'featimpini');
    @unlink($tmp);
    $tmpZip = $tmp.'.zip';
    featureMakeValidBackupZip($tmpZip);
    $importName = 'feature-import-ini-'.uniqid('', true).'.zip';

    $this->actingAs($admin)->post(route('admin.backups.import'), [
        'backup_file' => new UploadedFile($tmpZip, $importName, 'application/zip', null, true),
    ])->assertRedirect(route('admin.backups.index'));

    expect(ini_get('max_execution_time'))->toBe($maxExecutionBefore);

    @unlink($tmpZip);
    @unlink(BackupPathGuard::resolveExistingBackupPath($importName));
});

test('POST admin backups import restores max_execution_time when backup file is missing', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $maxExecutionBefore = ini_get('max_execution_time');

    $this->actingAs($admin)->post(route('admin.backups.import'), [])
        ->assertRedirect(route('admin.backups.index'));

    expect(ini_get('max_execution_time'))->toBe($maxExecutionBefore);
});

test('POST admin backups import restores max_execution_time when zip is rejected', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $maxExecutionBefore = ini_get('max_execution_time');

    $tmp = tempnam(sys_get_temp_dir(), 'featbadini');
    @unlink($tmp);
    $tmpZip = $tmp.'.zip';
    $zip = new ZipArchive;
    $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('readme.txt', 'no sql');
    $zip->close();

    $this->actingAs($admin)->post(route('admin.backups.import'), [
        'backup_file' => new UploadedFile($tmpZip, 'no-sql-ini.zip', 'application/zip', null, true),
    ])->assertRedirect(route('admin.backups.index'));

    expect(ini_get('max_execution_time'))->toBe($maxExecutionBefore);

    @unlink($tmpZip);
});

test('POST admin backups import restores max_execution_time when duplicate filename is rejected', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $maxExecutionBefore = ini_get('max_execution_time');
    $importName = 'duplicate-ini-'.uniqid('', true).'.zip';

    $tmp = tempnam(sys_get_temp_dir(), 'featdupini');
    @unlink($tmp);
    $tmpZip = $tmp.'.zip';
    featureMakeValidBackupZip($tmpZip);

    $this->actingAs($admin)->post(route('admin.backups.import'), [
        'backup_file' => new UploadedFile($tmpZip, $importName, 'application/zip', null, true),
    ])->assertRedirect(route('admin.backups.index'));

    $tmpSecond = tempnam(sys_get_temp_dir(), 'featdupini2');
    @unlink($tmpSecond);
    $tmpZipSecond = $tmpSecond.'.zip';
    featureMakeValidBackupZip($tmpZipSecond);

    $this->actingAs($admin)->post(route('admin.backups.import'), [
        'backup_file' => new UploadedFile($tmpZipSecond, $importName, 'application/zip', null, true),
    ])->assertRedirect(route('admin.backups.index'))
        ->assertSessionHas('error');

    expect(ini_get('max_execution_time'))->toBe($maxExecutionBefore);

    @unlink($tmpZipSecond);
    @unlink(BackupPathGuard::resolveExistingBackupPath($importName));
});

test('POST admin backups import accepts valid zip for admin user', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $tmp = tempnam(sys_get_temp_dir(), 'featimp');
    @unlink($tmp);
    $tmpZip = $tmp.'.zip';
    featureMakeValidBackupZip($tmpZip);

    $importName = 'feature-import-'.uniqid('', true).'.zip';

    $response = $this->actingAs($admin)->post(route('admin.backups.import'), [
        'backup_file' => new UploadedFile($tmpZip, $importName, 'application/zip', null, true),
    ]);

    $response->assertRedirect(route('admin.backups.index'));
    $response->assertSessionHas('success');
    $response->assertSessionHas('import_preview');

    expect(is_file(BackupPathGuard::resolveExistingBackupPath($importName)))->toBeTrue();
    $verify = app(BackupManifestService::class)->verifyIntegrity($importName);
    expect($verify['result'])->toBe(BackupManifest::INTEGRITY_VALID);
    expect(BackupMetadataService::readForZip($importName)['source'] ?? null)
        ->toBe(BackupMetadataService::SOURCE_IMPORT);

    @unlink($tmpZip);
});

test('POST admin backups import rejects zip without sql', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $tmp = tempnam(sys_get_temp_dir(), 'featbad');
    @unlink($tmp);
    $tmpZip = $tmp.'.zip';
    $zip = new \ZipArchive;
    $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('readme.txt', 'no sql');
    $zip->close();

    $response = $this->actingAs($admin)->post(route('admin.backups.import'), [
        'backup_file' => new UploadedFile($tmpZip, 'no-sql.zip', 'application/zip', null, true),
    ]);

    $response->assertRedirect(route('admin.backups.index'));
    $response->assertSessionHas('error');

    @unlink($tmpZip);
});

test('POST admin backups import requires authentication', function () {
    $response = $this->post(route('admin.backups.import'), []);
    $response->assertRedirect(route('login'));
});

test('POST admin backups import forbids non admin user', function () {
    $user = User::factory()->create(['role' => 'vendeur']);

    $response = $this->actingAs($user)->post(route('admin.backups.import'), []);
    expect(in_array($response->status(), [403, 302], true))->toBeTrue();
});
