<?php

namespace App\Notifications\Backup;

use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification as BaseNotification;

class BackupWasSuccessfulNotification extends BaseNotification
{
    public function __construct(BackupWasSuccessful $event)
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
