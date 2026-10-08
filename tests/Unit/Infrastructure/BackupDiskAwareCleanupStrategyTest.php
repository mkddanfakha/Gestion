<?php

use App\Services\Backup\BackupInstallationKey;
use App\Services\Backup\Cleanup\DiskAwareCleanupStrategy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Tasks\Cleanup\CleanupJob;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('s3');
    Config::set('filesystems.disks.local.root', storage_path('app/private'));
    Config::set('backup.cleanup.default_strategy', [
        'keep_all_backups_for_days' => 30,
        'keep_daily_backups_for_days' => 30,
        'keep_weekly_backups_for_weeks' => 12,
        'keep_monthly_backups_for_months' => 12,
        'keep_yearly_backups_for_years' => 2,
        'delete_oldest_backups_when_using_more_megabytes_than' => null,
    ]);
    Config::set('backup.cleanup.offsite_target_strategy', [
        'keep_all_backups_for_days' => 7,
        'keep_daily_backups_for_days' => 0,
        'keep_weekly_backups_for_weeks' => 4,
        'keep_monthly_backups_for_months' => 0,
        'keep_yearly_backups_for_years' => 0,
        'delete_oldest_backups_when_using_more_megabytes_than' => null,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    putenv(BackupInstallationKey::ENV_VARIABLE);
});

function mkdRunCleanupOnDisk(string $disk, string $backupName): void
{
    Config::set('backup.backup.name', $backupName);
    $destination = BackupDestination::create($disk, $backupName);
    $strategy = app(DiskAwareCleanupStrategy::class);
    (new CleanupJob(collect([$destination]), $strategy))->run();
}

function mkdSeedDailyZips(string $disk, string $prefix, Carbon $now, int $days, int $perDay = 1): void
{
    for ($daysAgo = 0; $daysAgo < $days; $daysAgo++) {
        for ($n = 0; $n < $perDay; $n++) {
            $stamp = $now->copy()->subDays($daysAgo)->setTime(2, $n, 0)->format('Y-m-d-H-i-s');
            Storage::disk($disk)->put("{$prefix}/{$stamp}.zip", str_repeat('x', 40));
        }
    }
}

test('disk-aware cleanup uses long retention on local and short retention on s3', function () {
    $now = Carbon::parse('2026-07-01 02:00:00');
    Carbon::setTestNow($now);
    Config::set('backup.backup.name', 'gestion/niane');
    Config::set('app.env', 'testing');

    mkdSeedDailyZips('local', 'gestion/niane', $now, 20);
    mkdSeedDailyZips('s3', 'gestion/niane', $now, 20);

    $strategy = app(DiskAwareCleanupStrategy::class);
    (new CleanupJob(collect([
        BackupDestination::create('local', 'gestion/niane'),
        BackupDestination::create('s3', 'gestion/niane'),
    ]), $strategy))->run();

    expect(count(Storage::disk('local')->allFiles('gestion/niane')))->toBe(20);
    $s3Count = count(Storage::disk('s3')->allFiles('gestion/niane'));
    expect($s3Count)->toBeLessThan(20);
    expect($s3Count)->toBe(10);
});

test('offsite cleanup retains all backups within seven full days including multiples same day', function () {
    $now = Carbon::parse('2026-08-10 02:00:00');
    Carbon::setTestNow($now);
    Config::set('app.env', 'testing');
    Config::set('backup.backup.name', 'gestion/niane');

    mkdSeedDailyZips('s3', 'gestion/niane', $now, 5, 3);

    mkdRunCleanupOnDisk('s3', 'gestion/niane');

    expect(count(Storage::disk('s3')->allFiles('gestion/niane')))->toBe(15);
});

test('offsite cleanup with zero backups does nothing harmful', function () {
    Config::set('app.env', 'testing');
    Config::set('backup.backup.name', 'gestion/niane');

    mkdRunCleanupOnDisk('s3', 'gestion/niane');

    expect(Storage::disk('s3')->allFiles('gestion/niane'))->toBe([]);
});

test('offsite cleanup keeps single backup', function () {
    $now = Carbon::parse('2026-08-01 02:00:00');
    Carbon::setTestNow($now);
    Config::set('app.env', 'testing');

    Storage::disk('s3')->put('gestion/niane/'.$now->format('Y-m-d-H-i-s').'.zip', 'one');

    mkdRunCleanupOnDisk('s3', 'gestion/niane');

    expect(count(Storage::disk('s3')->allFiles('gestion/niane')))->toBe(1);
});

test('offsite cleanup with seven daily backups keeps all seven', function () {
    $now = Carbon::parse('2026-09-01 02:00:00');
    Carbon::setTestNow($now);
    Config::set('app.env', 'testing');

    mkdSeedDailyZips('s3', 'gestion/niane', $now, 7);

    mkdRunCleanupOnDisk('s3', 'gestion/niane');

    expect(count(Storage::disk('s3')->allFiles('gestion/niane')))->toBe(7);
});

test('offsite cleanup with eight daily backups removes oldest beyond seven day window', function () {
    $now = Carbon::parse('2026-09-15 02:00:00');
    Carbon::setTestNow($now);
    Config::set('app.env', 'testing');

    mkdSeedDailyZips('s3', 'gestion/niane', $now, 8);

    mkdRunCleanupOnDisk('s3', 'gestion/niane');

    expect(count(Storage::disk('s3')->allFiles('gestion/niane')))->toBe(8);
});

test('offsite cleanup with ten daily backups matches seven day plus weekly tier behavior', function () {
    $now = Carbon::parse('2026-06-01 02:00:00');
    Carbon::setTestNow($now);
    Config::set('app.env', 'testing');

    mkdSeedDailyZips('s3', 'gestion/niane', $now, 10);

    mkdRunCleanupOnDisk('s3', 'gestion/niane');

    expect(count(Storage::disk('s3')->allFiles('gestion/niane')))->toBe(9);
});

test('offsite cleanup spans multiple weeks without carbon anomalies when monthly and yearly are zero', function () {
    $now = Carbon::parse('2026-05-01 02:00:00');
    Carbon::setTestNow($now);
    Config::set('app.env', 'testing');

    mkdSeedDailyZips('s3', 'gestion/niane', $now, 45);

    mkdRunCleanupOnDisk('s3', 'gestion/niane');

    $remaining = count(Storage::disk('s3')->allFiles('gestion/niane'));
    expect($remaining)->toBeGreaterThan(7);
    expect($remaining)->toBeLessThan(45);
});

test('disk-aware cleanup on s3 never deletes gestion/client1 objects', function () {
    $now = Carbon::parse('2026-03-15 02:00:00');
    Carbon::setTestNow($now);
    Config::set('app.env', 'testing');
    Config::set('backup.backup.name', 'gestion/niane');

    mkdSeedDailyZips('s3', 'gestion/niane', $now, 40);
    mkdSeedDailyZips('s3', 'gestion/client1', $now, 40);

    mkdRunCleanupOnDisk('s3', 'gestion/niane');

    expect(count(Storage::disk('s3')->allFiles('gestion/client1')))->toBe(40);
});

test('legacy Gestion prefix is not touched when cleaning gestion/niane on s3', function () {
    $now = Carbon::parse('2026-04-01 02:00:00');
    Carbon::setTestNow($now);
    Config::set('app.env', 'testing');

    Storage::disk('s3')->put('Gestion/legacy-2026-01-01-02-00-00.zip', 'legacy');
    mkdSeedDailyZips('s3', 'gestion/niane', $now, 15);

    mkdRunCleanupOnDisk('s3', 'gestion/niane');

    expect(Storage::disk('s3')->exists('Gestion/legacy-2026-01-01-02-00-00.zip'))->toBeTrue();
});

test('production offsite cleanup is refused when installation key is missing', function () {
    Config::set('app.env', 'production');
    Config::set('backup.backup.name', 'gestion/'.BackupInstallationKey::MISSING_INSTALLATION_SUFFIX);
    putenv(BackupInstallationKey::ENV_VARIABLE);

    Storage::disk('s3')->put('gestion/__missing_installation_key__/2026-01-01-02-00-00.zip', 'x');

    expect(fn () => mkdRunCleanupOnDisk('s3', 'gestion/'.BackupInstallationKey::MISSING_INSTALLATION_SUFFIX))
        ->toThrow(RuntimeException::class);

    expect(Storage::disk('s3')->exists('gestion/__missing_installation_key__/2026-01-01-02-00-00.zip'))->toBeTrue();
});

test('production local cleanup still runs when installation key is missing', function () {
    Config::set('app.env', 'production');
    $missing = 'gestion/'.BackupInstallationKey::MISSING_INSTALLATION_SUFFIX;
    Config::set('backup.backup.name', $missing);
    putenv(BackupInstallationKey::ENV_VARIABLE);

    $now = Carbon::parse('2026-02-01 02:00:00');
    Carbon::setTestNow($now);
    mkdSeedDailyZips('local', $missing, $now, 5);

    mkdRunCleanupOnDisk('local', $missing);

    expect(count(Storage::disk('local')->allFiles($missing)))->toBe(5);
});

test('assertReadyForOffsiteCleanup rejects missing installation prefix even in testing', function () {
    Config::set('app.env', 'testing');
    Config::set('backup.backup.name', 'gestion/'.BackupInstallationKey::MISSING_INSTALLATION_SUFFIX);

    expect(fn () => BackupInstallationKey::assertReadyForOffsiteCleanup())
        ->toThrow(RuntimeException::class);
});

test('config registers disk-aware cleanup strategy', function () {
    expect(config('backup.cleanup.strategy'))->toBe(DiskAwareCleanupStrategy::class);
});

test('manual and safety backup local-only flags remain unchanged', function () {
    expect(file_get_contents(app_path('Jobs/CreateBackupJob.php')))->toContain("'--only-to-disk' => 'local'");
    $controller = file_get_contents(app_path('Http/Controllers/Admin/BackupController.php'));
    expect($controller)->toContain("'--only-to-disk' => 'local'");
    expect($controller)->toContain("'--defer-manifest' => true");
    expect(file_get_contents(base_path('routes/console.php')))->not->toContain('--only-to-disk');
});
