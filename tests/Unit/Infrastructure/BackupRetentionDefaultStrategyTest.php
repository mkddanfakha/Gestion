<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Tasks\Cleanup\CleanupJob;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;

beforeEach(function () {
    Storage::fake('s3');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('default strategy with 7 all + 4 weekly retains expected offsite count for daily cron backups', function () {
    $now = Carbon::parse('2026-06-01 02:00:00');
    Carbon::setTestNow($now);

    // 10 consecutive daily automatic backups (one per day).
    for ($daysAgo = 0; $daysAgo < 10; $daysAgo++) {
        $stamp = $now->copy()->subDays($daysAgo)->format('Y-m-d-H-i-s');
        Storage::disk('s3')->put("gestion/niane/{$stamp}.zip", str_repeat('x', 50));
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
    (new CleanupJob(collect([$destination]), $strategy))->run();

    $remaining = Storage::disk('s3')->allFiles('gestion/niane');
    sort($remaining);

    // Spatie 9.3.7 DefaultStrategy with 7/0/4 on 10 daily archives: 9 remain (1 removed).
    expect(count($remaining))->toBe(9);
    expect(count($remaining))->toBeLessThan(10);
});

test('offsite target strategy config documents 7 daily and 4 weekly retention', function () {
    $target = config('backup.cleanup.offsite_target_strategy');
    expect($target['keep_all_backups_for_days'])->toBe(7);
    expect($target['keep_daily_backups_for_days'])->toBe(0);
    expect($target['keep_weekly_backups_for_weeks'])->toBe(4);
    expect($target['delete_oldest_backups_when_using_more_megabytes_than'])->toBeNull();
});

test('active cleanup strategy keeps longer local retention until per-disk split', function () {
    $active = config('backup.cleanup.default_strategy');
    expect($active['keep_all_backups_for_days'])->toBe(30);
    expect($active['keep_daily_backups_for_days'])->toBe(30);
    expect($active['delete_oldest_backups_when_using_more_megabytes_than'])->toBeNull();
});
