<?php

namespace App\Notifications\Backup;

use Spatie\Backup\Events\HealthyBackupWasFound;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification as BaseNotification;

class HealthyBackupWasFoundNotification extends BaseNotification
{
    public function __construct(HealthyBackupWasFound $event)
    {
        parent::__construct($event);
    }

    /**
     * @return list<string>
     */
    public function via(): array
    {
        return BackupAlertChannels::mailWhenConfigured();
    }
}
