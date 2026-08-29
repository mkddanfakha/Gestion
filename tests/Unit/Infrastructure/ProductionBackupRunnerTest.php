<?php

use App\Console\Commands\RunProductionBackupCommand;
use App\Database\BackupConcurrencyGuard;
use App\Database\DatabaseAccountGuard;
use App\Database\PrivilegedCredentialLoader;
use App\Database\PrivilegedProcessResult;
use App\Database\PrivilegedProcessRunner;
use App\Database\ProtectedDatabaseException;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

afterEach(function () {
    putenv(PrivilegedProcessRunner::SUBPROCESS_MARKER);
    BackupConcurrencyGuard::releaseBackup();
    BackupConcurrencyGuard::releaseRestore();
});

test('credential loader parses backup secret file without exposing password in exceptions', function () {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'.mysql-gestion-backup.local';

    file_put_contents($path, implode("\n", [
        'DB_USERNAME=gestion_backup',
        'DB_PASSWORD=super-secret-not-in-logs',
        'DB_HOST=127.0.0.1',
        'DB_DATABASE=gestion',
    ]));

    $loader = new PrivilegedCredentialLoader;
    $credentials = $loader->loadBackupCredentials($path);

    expect($credentials['username'])->toBe('gestion_backup');
    expect($credentials['password'])->toBe('super-secret-not-in-logs');
    expect($credentials['database'])->toBe('gestion');

    @unlink($path);
});

test('credential loader rejects missing backup file', function () {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'.mysql-gestion-backup.local';
    @unlink($path);

    $loader = new PrivilegedCredentialLoader;

    expect(fn () => $loader->loadBackupCredentials($path))
        ->toThrow(RuntimeException::class);
});

test('credential loader rejects wrong username', function () {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'.mysql-gestion-backup.local';

    file_put_contents($path, implode("\n", [
        'DB_USERNAME=gestion_app',
        'DB_PASSWORD=x',
        'DB_DATABASE=gestion',
    ]));

    $loader = new PrivilegedCredentialLoader;

    expect(fn () => $loader->loadBackupCredentials($path))
        ->toThrow(RuntimeException::class);

    @unlink($path);
});

test('process runner puts password in env not argv', function () {
    $runner = new PrivilegedProcessRunner(new PrivilegedCredentialLoader);

    $credentials = [
        'username' => 'gestion_backup',
        'password' => 'argv-must-not-contain-this',
        'host' => '127.0.0.1',
        'port' => null,
        'database' => 'gestion',
    ];

    $command = $runner->buildArtisanCommand('backup:run');
    $environment = $runner->buildBackupSubprocessEnvironment($credentials);

    expect(implode(' ', $command))->not->toContain('argv-must-not-contain-this');
    expect($environment['DB_PASSWORD'])->toBe('argv-must-not-contain-this');
    expect($environment['DB_USERNAME'])->toBe('gestion_backup');
    expect($environment['CACHE_STORE'])->toBe('file');
    expect($environment['BACKUP_LOCK_CACHE_STORE'])->toBe('file');
    expect($environment[PrivilegedProcessRunner::SUBPROCESS_MARKER])->toBe('backup');
});

test('sanitizeForLog redacts secrets from subprocess output', function () {
    $secret = 'hidden-db-password-value';
    $text = "mysqldump failed: Access denied for user using password {$secret}";

    $sanitized = PrivilegedCredentialLoader::sanitizeForLog($text, $secret);

    expect($sanitized)->not->toContain($secret);
    expect($sanitized)->toContain('[REDACTED]');
});

test('privileged subprocess marker is detected', function () {
    putenv(PrivilegedProcessRunner::SUBPROCESS_MARKER.'=backup');
    expect(DatabaseAccountGuard::isPrivilegedSubprocess())->toBeTrue();

    putenv(PrivilegedProcessRunner::SUBPROCESS_MARKER);
    expect(DatabaseAccountGuard::isPrivilegedSubprocess())->toBeFalse();
});

test('backup:run refuses gestion_app direct execution', function () {
    Config::set('database.connections.mysql.username', 'gestion_app');

    expect(fn () => Artisan::call('backup:run', ['--only-db' => true]))
        ->toThrow(ProtectedDatabaseException::class);
});

test('backup:production command exists', function () {
    expect(class_exists(RunProductionBackupCommand::class))->toBeTrue();
});

test('backup:production delegates to isolated runner without real backup', function () {
    $fakeRunner = new class extends PrivilegedProcessRunner
    {
        public function __construct() {}

        public function runBackupRun(bool $onlyDb = false, ?array $credentials = null): PrivilegedProcessResult
        {
            return new PrivilegedProcessResult(0, 'simulated-backup-success', '');
        }
    };

    app()->instance(PrivilegedProcessRunner::class, $fakeRunner);

    $exitCode = Artisan::call('backup:production');

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('simulated-backup-success');
});

test('backup:production surfaces subprocess failure without secrets', function () {
    $secret = 'leaked-if-not-redacted';

    $fakeRunner = new class($secret) extends PrivilegedProcessRunner
    {
        public function __construct(private string $secret) {}

        public function runBackupRun(bool $onlyDb = false, ?array $credentials = null): PrivilegedProcessResult
        {
            return new PrivilegedProcessResult(
                1,
                '',
                PrivilegedCredentialLoader::sanitizeForLog("failed with {$this->secret}", $this->secret),
            );
        }
    };

    app()->instance(PrivilegedProcessRunner::class, $fakeRunner);

    $exitCode = Artisan::call('backup:production');
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->not->toContain($secret);
    expect($output)->toContain('[REDACTED]');
});

test('scheduler daily backup uses backup:production not backup:run', function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $backupEvents = collect($schedule->events())->filter(function (Event $event) {
        $expression = (string) ($event->command ?? $event->description ?? '');

        return str_contains($expression, 'backup:production');
    });

    expect($backupEvents)->not->toBeEmpty();

    $directRunEvents = collect($schedule->events())->filter(function (Event $event) {
        $expression = (string) ($event->command ?? '');

        return preg_match('/\bbackup:run\b/', $expression) === 1
            && ! str_contains($expression, 'backup:production');
    });

    expect($directRunEvents)->toBeEmpty();
});

test('runtime privileged accounts remain forbidden outside subprocess marker', function () {
    foreach (['gestion_backup', 'gestion_restore', 'gestion_migration', 'root'] as $user) {
        Config::set('database.connections.mysql.username', $user);

        expect(fn () => DatabaseAccountGuard::assertRuntimeUsernameAllowed())
            ->toThrow(ProtectedDatabaseException::class);
    }
});

test('runtime gestion_app remains allowed', function () {
    Config::set('database.connections.mysql.username', 'gestion_app');
    DatabaseAccountGuard::assertRuntimeUsernameAllowed();
    expect(true)->toBeTrue();
});
