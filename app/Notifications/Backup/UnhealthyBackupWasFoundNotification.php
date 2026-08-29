<?php

namespace App\Notifications\Backup;

use Spatie\Backup\Events\UnhealthyBackupWasFound;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification as BaseNotification;

class UnhealthyBackupWasFoundNotification extends BaseNotification
{
    public function __construct(UnhealthyBackupWasFound $event)
    {
        parent::__construct($event);
    }

    /**
     * @return list<string>
     */
    public function via(): array
    {
        \Log::warning('backup.unhealthy', [
            'message' => 'unhealthy_backup_was_found',
        ]);

        return BackupAlertChannels::mailWhenConfigured();
    }
}
