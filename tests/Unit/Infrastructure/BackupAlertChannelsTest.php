<?php

use App\Notifications\Backup\BackupAlertChannels;

test('backup mail alerts are disabled by default', function () {
    expect(BackupAlertChannels::mailWhenConfigured())->toBe([]);
    expect(BackupAlertChannels::isMailAlertConfigured())->toBeFalse();
});

test('backup mail alerts enable only with flag and valid email', function () {
    // env() reads process env; putenv for this process only (no .env write)
    putenv('BACKUP_ALERT_MAIL_ENABLED=true');
    putenv('BACKUP_ALERT_EMAIL=ops@example.test');
    $_ENV['BACKUP_ALERT_MAIL_ENABLED'] = 'true';
    $_ENV['BACKUP_ALERT_EMAIL'] = 'ops@example.test';

    expect(BackupAlertChannels::mailWhenConfigured())->toBe(['mail']);

    putenv('BACKUP_ALERT_MAIL_ENABLED=false');
    putenv('BACKUP_ALERT_EMAIL=');
    $_ENV['BACKUP_ALERT_MAIL_ENABLED'] = 'false';
    $_ENV['BACKUP_ALERT_EMAIL'] = '';
});
