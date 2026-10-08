<?php

use App\Services\Backup\BackupInstallationKey;
use Illuminate\Support\Facades\Config;

afterEach(function () {
    putenv(BackupInstallationKey::ENV_VARIABLE);
});

test('installation key format accepts valid slugs', function (string $key) {
    expect(BackupInstallationKey::isValidKeyFormat($key))->toBeTrue();
    expect(BackupInstallationKey::backupNameForKey($key))->toBe('gestion/'.$key);
})->with([
    'niane',
    'client1',
    'client-1',
    'boutique-kedougou',
]);

test('installation key format rejects invalid slugs', function (string $key) {
    expect(BackupInstallationKey::isValidKeyFormat($key))->toBeFalse();
})->with([
    '../niane',
    '/niane',
    'Niane',
    'client/1',
    'client..1',
    'gestion/niane',
    'client_1',
    'client.1',
    '',
]);

test('backup name for niane is gestion/niane', function () {
    expect(BackupInstallationKey::backupNameForKey('niane'))->toBe('gestion/niane');
    expect(BackupInstallationKey::backupNameForKey('client1'))->toBe('gestion/client1');
});

test('configured backup name is valid when config uses gestion prefix', function () {
    Config::set('backup.backup.name', 'gestion/niane');
    expect(BackupInstallationKey::isValidConfiguredBackupName('gestion/niane'))->toBeTrue();
    expect(BackupInstallationKey::configuredInstallationKey())->toBe('niane');
});

test('monitor config name matches backup name helper when env key is set', function () {
    putenv(BackupInstallationKey::ENV_VARIABLE.'=niane');
    $resolved = BackupInstallationKey::resolveBackupNameFromEnvironment();
    expect($resolved)->toBe('gestion/niane');
});

test('production backup operation requires valid installation key', function () {
    Config::set('app.env', 'production');
    Config::set('backup.backup.name', 'gestion/__missing_installation_key__');
    putenv(BackupInstallationKey::ENV_VARIABLE);

    expect(fn () => BackupInstallationKey::assertReadyForBackupOperation())
        ->toThrow(RuntimeException::class);
});

test('testing environment skips strict installation key assertion', function () {
    Config::set('app.env', 'testing');
    Config::set('backup.backup.name', 'gestion/test-backups');

    BackupInstallationKey::assertReadyForBackupOperation();
    expect(true)->toBeTrue();
});
