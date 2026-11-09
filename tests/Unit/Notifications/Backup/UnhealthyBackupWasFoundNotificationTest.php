<?php

uses(Tests\TestCase::class);

use App\Notifications\Backup\UnhealthyBackupWasFoundNotification;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Events\UnhealthyBackupWasFound;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatus;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;

test('unhealthy backup notification logs structured warning context', function () {
    Storage::fake('local');

    $destination = BackupDestination::create('local', 'mkd-pro-backup');
    $status = new BackupDestinationStatus($destination, [new MaximumAgeInDays(1)]);

    expect($status->isHealthy())->toBeFalse();

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event;
    });

    $notification = new UnhealthyBackupWasFoundNotification(new UnhealthyBackupWasFound($status));
    $notification->via();

    expect($logged)->not->toBeEmpty();

    $warning = collect($logged)->first(
        fn (MessageLogged $log) => $log->level === 'warning' && $log->message === 'backup.unhealthy'
    );

    expect($warning)->not->toBeNull();

    $context = $warning->context;

    expect($context['message'])->toBe('unhealthy_backup_was_found')
        ->and($context['disk'])->toBe('local')
        ->and($context['backup_name'])->toBe('mkd-pro-backup')
        ->and($context['health_check'])->toBe(MaximumAgeInDays::class)
        ->and($context['exception_class'])->toBeString()->not->toBe('')
        ->and($context['exception_message'])->toBeString()->not->toBe('');
});

test('unhealthy backup notification logs null failure fields when health check failure is absent', function () {
    Storage::fake('local');

    $destination = BackupDestination::create('local', 'offsite-backup');
    $status = new BackupDestinationStatus($destination);

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event;
    });

    $notification = new UnhealthyBackupWasFoundNotification(new UnhealthyBackupWasFound($status));
    $notification->via();

    $warning = collect($logged)->first(
        fn (MessageLogged $log) => $log->level === 'warning' && $log->message === 'backup.unhealthy'
    );

    expect($warning)->not->toBeNull();

    $context = $warning->context;

    expect($context['disk'])->toBe('local')
        ->and($context['backup_name'])->toBe('offsite-backup')
        ->and($context)->toHaveKeys(['health_check', 'exception_class', 'exception_message'])
        ->and($context['health_check'])->toBeNull()
        ->and($context['exception_class'])->toBeNull()
        ->and($context['exception_message'])->toBeNull();
});

test('unhealthy backup notification via returns no mail channel when backup alerts are disabled', function () {
    withTemporaryBackupAlertEnvironment([
        'BACKUP_ALERT_MAIL_ENABLED' => 'false',
        'BACKUP_ALERT_EMAIL' => '',
    ], function () {
        $channels = unhealthyBackupNotificationVia();

        expect($channels)->toBe([]);
    });
});

test('unhealthy backup notification via returns mail channel when backup alerts are configured', function () {
    withTemporaryBackupAlertEnvironment([
        'BACKUP_ALERT_MAIL_ENABLED' => 'true',
        'BACKUP_ALERT_EMAIL' => 'ops@example.test',
    ], function () {
        $channels = unhealthyBackupNotificationVia();

        expect($channels)->toBe(['mail']);
    });
});

/**
 * @param  array<string, string>  $overrides
 */
function withTemporaryBackupAlertEnvironment(array $overrides, callable $callback): mixed
{
    $snapshot = snapshotBackupAlertEnvironmentVariables();

    try {
        applyBackupAlertEnvironmentVariables($overrides);

        return $callback();
    } finally {
        restoreBackupAlertEnvironmentVariables($snapshot);
    }
}

/**
 * @return array{
 *     getenv: array<string, string|false>,
 *     env: array<string, string|null>,
 *     server: array<string, string|null>
 * }
 */
function snapshotBackupAlertEnvironmentVariables(): array
{
    $keys = ['BACKUP_ALERT_MAIL_ENABLED', 'BACKUP_ALERT_EMAIL'];

    $getenv = [];
    $env = [];
    $server = [];

    foreach ($keys as $key) {
        $getenv[$key] = getenv($key);
        $env[$key] = array_key_exists($key, $_ENV) ? (string) $_ENV[$key] : null;
        $server[$key] = array_key_exists($key, $_SERVER) ? (string) $_SERVER[$key] : null;
    }

    return [
        'getenv' => $getenv,
        'env' => $env,
        'server' => $server,
    ];
}

/**
 * @param  array<string, string>  $values
 */
function applyBackupAlertEnvironmentVariables(array $values): void
{
    foreach ($values as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

/**
 * @param  array{
 *     getenv: array<string, string|false>,
 *     env: array<string, string|null>,
 *     server: array<string, string|null>
 * }  $snapshot
 */
function restoreBackupAlertEnvironmentVariables(array $snapshot): void
{
    foreach ($snapshot['getenv'] as $key => $value) {
        if ($value === false) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv("{$key}={$value}");
        }
    }

    foreach ($snapshot['env'] as $key => $value) {
        if ($value === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $value;
        }
    }

    foreach ($snapshot['server'] as $key => $value) {
        if ($value === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $value;
        }
    }
}

/**
 * @return list<string>
 */
function unhealthyBackupNotificationVia(): array
{
    Storage::fake('local');

    $destination = BackupDestination::create('local', 'mkd-pro-backup');
    $status = new BackupDestinationStatus($destination, [new MaximumAgeInDays(1)]);
    $status->isHealthy();

    $notification = new UnhealthyBackupWasFoundNotification(new UnhealthyBackupWasFound($status));

    return $notification->via();
}
