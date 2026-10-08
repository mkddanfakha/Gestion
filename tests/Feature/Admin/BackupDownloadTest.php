<?php

use App\Models\User;
use App\Services\Backup\BackupPathGuard;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    $this->withoutVite();

    Config::set('backup.backup.name', 'gestion/feature-download');
    Config::set('backup.backup.destination.disks', ['local']);
    Config::set('filesystems.disks.local.root', storage_path('app/private'));
});

function featureDownloadZip(string $filename): string
{
    $folder = config('backup.backup.name');
    $dir = storage_path('app/private/'.$folder);

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $path = $dir.DIRECTORY_SEPARATOR.$filename;

    $zip = new ZipArchive();

    expect($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();

    $zip->addFromString(
        'download-test.txt',
        'MKD-Pro backup streaming test',
    );

    $zip->close();

    return $path;
}

test('GET admin backups download requires authentication', function () {
    $name = 'feat-download-auth.zip';
    featureDownloadZip($name);

    $response = $this->get(route('admin.backups.download', $name));

    $response->assertRedirect(route('login'));
});

test('GET admin backups download streams the backup without loading it into memory', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $name = 'feat-download-'.uniqid('', true).'.zip';
    $path = featureDownloadZip($name);
    $expectedContent = file_get_contents($path);
    $expectedSize = filesize($path);

    expect($expectedContent)->not->toBeFalse();
    expect($expectedSize)->not->toBeFalse();

    $response = $this->actingAs($admin)->get(
        route('admin.backups.download', $name)
    );

    $response->assertOk();

    expect($response->baseResponse)->toBeInstanceOf(
        \Symfony\Component\HttpFoundation\StreamedResponse::class
    );

    $response->assertHeader('Content-Type', 'application/zip');
    $response->assertHeader('Content-Length', (string) $expectedSize);
    $response->assertHeader('Cache-Control', 'no-store, no-cache, must-revalidate');

    expect($response->streamedContent())->toBe($expectedContent);

    expect(is_file(BackupPathGuard::resolveExistingBackupPath($name)))->toBeTrue();
});
