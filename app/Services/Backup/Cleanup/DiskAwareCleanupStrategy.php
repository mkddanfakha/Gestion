<?php

namespace App\Services\Backup\Cleanup;

use App\Services\Backup\BackupInstallationKey;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;
use Spatie\Backup\BackupDestination\BackupCollection;
use Spatie\Backup\Tasks\Cleanup\Period;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;

/**
 * Applies local retention (default_strategy) and offsite retention (offsite_target_strategy) per disk.
 */
final class DiskAwareCleanupStrategy extends DefaultStrategy
{
    public function deleteOldBackups(BackupCollection $backups): void
    {
        if ($this->backupDestination()->diskName() === 's3') {
            BackupInstallationKey::assertReadyForOffsiteCleanup();
        }

        parent::deleteOldBackups($backups);
    }

    /** @return Collection<string, Period> */
    protected function calculateDateRanges(): Collection
    {
        $config = $this->resolvedStrategyConfig();

        $daily = new Period(
            Carbon::now()->subDays($config['keep_all_backups_for_days']),
            Carbon::now()
                ->subDays($config['keep_all_backups_for_days'])
                ->subDays($config['keep_daily_backups_for_days'])
        );

        $weekly = new Period(
            $daily->endDate(),
            $daily->endDate()
                ->subWeeks($config['keep_weekly_backups_for_weeks'])
        );

        $monthly = new Period(
            $weekly->endDate(),
            $weekly->endDate()
                ->subMonths($config['keep_monthly_backups_for_months'])
        );

        $yearly = new Period(
            $monthly->endDate(),
            $monthly->endDate()
                ->subYears($config['keep_yearly_backups_for_years'])
        );

        return collect(compact('daily', 'weekly', 'monthly', 'yearly'));
    }

    protected function shouldRemoveOldestBackup(BackupCollection $backups): bool
    {
        if (! $backups->oldest()) {
            return false;
        }

        $maximumSize = $this->resolvedStrategyConfig()['delete_oldest_backups_when_using_more_megabytes_than'] ?? null;

        if ($maximumSize === null) {
            return false;
        }

        if (($backups->size() + $this->newestBackup->sizeInBytes()) <= $maximumSize * 1024 * 1024) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvedStrategyConfig(): array
    {
        $disk = $this->backupDestination()->diskName();

        if ($disk === 's3') {
            return $this->config->get('backup.cleanup.offsite_target_strategy');
        }

        return $this->config->get('backup.cleanup.default_strategy');
    }
}
