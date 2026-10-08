<?php

use App\Models\User;
use App\Services\Backup\BackupPathGuard;
use App\Services\Restore\BackupRestoreRejectedException;
use App\Services\Restore\DatabaseRestoreService;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    $this->withoutVite();
    Config::set('backup.backup.name', 'gestion/feature-restore');
    Config::set('backup.backup.destination.disks', ['local']);
    Config::set('filesystems.disks.local.root', storage_path('app/private'));
    Config::set('database-safety.restore_allowed_databases', ['gestion_recovery', 'gestion_test']);
    Config::set('database-safety.protected_databases', ['gestion']);
});

function featureRestoreZip(string $filename): void
{
    $folder = config('backup.backup.name');
    $dir = storage_path('app/private/'.$folder);
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $path = $dir.DIRECTORY_SEPARATOR.$filename;
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString(
        'db-dumps/mysql-gestion.sql',
        "CREATE TABLE `users` (`id` int);\nINSERT INTO `users` VALUES (1);\n",
    );
    $zip->close();
}

test('POST admin backups restore requires authentication', function () {
    featureRestoreZip('feat-restore-auth.zip');

    $response = $this->post(route('admin.backups.restore', 'feat-restore-auth.zip'), []);
    $response->assertRedirect(route('login'));
});

test('POST admin backups restore forbids non admin user', function () {
    featureRestoreZip('feat-restore-forbidden.zip');
    $user = User::factory()->create(['role' => 'vendeur']);

    $response = $this->actingAs($user)->post(route('admin.backups.restore', 'feat-restore-forbidden.zip'), [
        'confirm' => true,
        'confirmation_phrase' => 'RESTORE',
        'target_database' => 'gestion_recovery',
        'restore_mode' => 'database',
        'acknowledge_overwrite' => true,
    ]);

    expect(in_array($response->status(), [403, 302], true))->toBeTrue();
});

test('POST admin backups restore rejects missing acknowledge_overwrite', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    featureRestoreZip('feat-restore-ack.zip');

    $response = $this->actingAs($admin)->post(route('admin.backups.restore', 'feat-restore-ack.zip'), [
        'confirm' => true,
        'confirmation_phrase' => 'RESTORE',
        'target_database' => 'gestion_recovery',
        'restore_mode' => 'database',
    ]);

    $response->assertSessionHasErrors('acknowledge_overwrite');
});

test('POST admin backups restore rejects protected database gestion', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    featureRestoreZip('feat-restore-gestion.zip');

    $response = $this->actingAs($admin)->post(route('admin.backups.restore', 'feat-restore-gestion.zip'), [
        'confirm' => true,
        'confirmation_phrase' => 'RESTORE',
        'target_database' => 'gestion',
        'restore_mode' => 'database',
        'acknowledge_overwrite' => true,
    ]);

    expect(in_array($response->status(), [403, 302], true))->toBeTrue();
});

test('POST admin backups restore rejects unknown target database', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    featureRestoreZip('feat-restore-evil.zip');

    $response = $this->actingAs($admin)->post(route('admin.backups.restore', 'feat-restore-evil.zip'), [
        'confirm' => true,
        'confirmation_phrase' => 'RESTORE',
        'target_database' => 'evil_database',
        'restore_mode' => 'database',
        'acknowledge_overwrite' => true,
    ]);

    expect(in_array($response->status(), [403, 302], true))->toBeTrue();
});

test('POST admin backups restore rejects wrong confirmation phrase at service layer', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    featureRestoreZip('feat-restore-phrase.zip');

    $response = $this->actingAs($admin)->post(route('admin.backups.restore', 'feat-restore-phrase.zip'), [
        'confirm' => true,
        'confirmation_phrase' => 'YES',
        'target_database' => 'gestion_recovery',
        'restore_mode' => 'database',
        'acknowledge_overwrite' => true,
        'safety_backup' => false,
    ]);

    $response->assertForbidden();
});

test('POST admin backups restore succeeds when service reports success', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $name = 'feat-restore-ok-'.uniqid('', true).'.zip';
    featureRestoreZip($name);

    $this->mock(DatabaseRestoreService::class, function ($mock) use ($name) {
        $mock->shouldReceive('restore')
            ->once()
            ->with($name, 'gestion_recovery', 'RESTORE', false)
            ->andReturn([
                'mode' => 'database',
                'backup' => $name,
                'target' => 'gestion_recovery',
                'status' => 'imported',
            ]);
    });

    $response = $this->actingAs($admin)->post(route('admin.backups.restore', $name), [
        'confirm' => true,
        'confirmation_phrase' => 'RESTORE',
        'target_database' => 'gestion_recovery',
        'restore_mode' => 'database',
        'acknowledge_overwrite' => true,
        'safety_backup' => false,
    ]);

    $response->assertRedirect(route('admin.backups.index'));
    $response->assertSessionHas('success');
    expect(is_file(BackupPathGuard::resolveExistingBackupPath($name)))->toBeTrue();
});

test('POST admin backups restore surfaces structured restore rejection', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $name = 'feat-restore-fail-'.uniqid('', true).'.zip';
    featureRestoreZip($name);

    $this->mock(DatabaseRestoreService::class, function ($mock) use ($name) {
        $mock->shouldReceive('restore')
            ->once()
            ->andThrow(new BackupRestoreRejectedException(
                BackupRestoreRejectedException::INTEGRITY_FAILED,
                'Backup integrity check failed.',
            ));
    });

    $response = $this->actingAs($admin)->post(route('admin.backups.restore', $name), [
        'confirm' => true,
        'confirmation_phrase' => 'RESTORE',
        'target_database' => 'gestion_recovery',
        'restore_mode' => 'database',
        'acknowledge_overwrite' => true,
        'safety_backup' => false,
    ]);

    $response->assertRedirect(route('admin.backups.index'));
    $response->assertSessionHas('error');
    expect(session('error'))->toContain('intégrité');
});
