<?php

use App\Database\BackupOperationalStatus;
use App\Jobs\CreateBackupJob;
use App\Services\Backup\BackupInstallationKey;
use App\Services\Backup\BackupPathGuard;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Tasks\Cleanup\CleanupJob;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;

test('backup destinations are isolated by gestion installation prefix on fake s3', function () {
    Storage::fake('s3');

    Storage::disk('s3')->put('gestion/niane/2026-01-10-02-00-00.zip', 'niane-archive');
    Storage::disk('s3')->put('gestion/client1/2026-01-10-02-00-00.zip', 'client1-archive');
    Storage::disk('s3')->put('Gestion/legacy.zip', 'legacy');

    $niane = BackupDestination::create('s3', 'gestion/niane');
    $client1 = BackupDestination::create('s3', 'gestion/client1');

    $nianeFiles = $niane->backups()->map(fn ($b) => $b->path())->all();
    $client1Files = $client1->backups()->map(fn ($b) => $b->path())->all();

    expect($nianeFiles)->toBe(['gestion/niane/2026-01-10-02-00-00.zip']);
    expect($client1Files)->toBe(['gestion/client1/2026-01-10-02-00-00.zip']);
    expect(Storage::disk('s3')->exists('Gestion/legacy.zip'))->toBeTrue();
});

test('cleanup on gestion/niane does not delete gestion/client1 objects', function () {
    Storage::fake('s3');

    $now = \Illuminate\Support\Carbon::parse('2026-03-15 02:00:00');
    \Illuminate\Support\Carbon::setTestNow($now);

    for ($daysAgo = 0; $daysAgo < 40; $daysAgo++) {
        $stamp = $now->copy()->subDays($daysAgo)->format('Y-m-d-H-i-s');
        Storage::disk('s3')->put("gestion/niane/{$stamp}.zip", str_repeat('a', 100));
        Storage::disk('s3')->put("gestion/client1/{$stamp}.zip", str_repeat('b', 100));
    }

    Config::set('backup.cleanup.default_strategy', [
        'keep_all_backups_for_days' => 7,
        'keep_daily_backups_for_days' => 0,
        'keep_weekly_backups_for_weeks' => 4,
        'keep_monthly_backups_for_months' => 0,
        'keep_yearly_backups_for_years' => 0,
        'delete_oldest_backups_when_using_more_megabytes_than' => null,
    ]);

    $destination = BackupDestination::create('s3', 'gestion/niane');
    $strategy = new DefaultStrategy(app('config'));
    $job = new CleanupJob(collect([$destination]), $strategy);
    $job->run();

    expect(Storage::disk('s3')->allFiles('gestion/client1'))->toHaveCount(40);

    \Illuminate\Support\Carbon::setTestNow();
});

test('BackupOperationalStatus inspects gestion installation prefix on fake s3', function () {
    Storage::fake('s3');
    Config::set('backup.backup.destination.disks', ['local', 's3']);
    Config::set('filesystems.disks.s3.bucket', 'test-bucket');
    Config::set('backup.backup.name', 'gestion/niane');

    Storage::disk('s3')->put('gestion/niane/'.now()->format('Y-m-d-H-i-s').'.zip', 'payload');

    $ops = BackupOperationalStatus::evaluate(now());
    expect($ops['offsite_detail']['archive_count'] ?? 0)->toBe(1);
    expect($ops['offsite_detail']['latest']['file'] ?? '')->toMatch('/\.zip$/');
});

test('BackupPathGuard resolves nested gestion installation directory on local disk', function () {
    Config::set('backup.backup.name', 'gestion/niane');
    Config::set('filesystems.disks.local.root', storage_path('app/private'));

    $dir = BackupPathGuard::backupDirectoryAbsolutePath();
    $normalized = strtolower(str_replace('\\', '/', $dir));
    expect($normalized)->toContain('storage/app/private/gestion/niane');
});

test('CreateBackupJob keeps manual local-only only-to-disk flag', function () {
    $source = file_get_contents(app_path('Jobs/CreateBackupJob.php'));
    expect($source)->toContain("'--only-to-disk' => 'local'");
});

test('BackupController safety restore keeps local-only flags', function () {
    $source = file_get_contents(app_path('Http/Controllers/Admin/BackupController.php'));
    expect($source)->toContain("'--only-to-disk' => 'local'");
    expect($source)->toContain("'--only-db' => true");
    expect($source)->toContain("'--defer-manifest' => true");
});

test('RunProductionBackupCommand does not force only-to-disk for scheduled path', function () {
    $source = file_get_contents(app_path('Console/Commands/RunProductionBackupCommand.php'));
    expect($source)->not->toContain("'--only-to-disk' => 'local'");
    expect($source)->toContain('$onlyToDisk');
});

test('monitor backup name uses gestion prefix when installation key env is set', function () {
    putenv(BackupInstallationKey::ENV_VARIABLE.'=niane');
    $name = BackupInstallationKey::resolveBackupNameFromEnvironment();
    expect($name)->toBe('gestion/niane');
    expect($name)->not->toBe('Gestion');
});
