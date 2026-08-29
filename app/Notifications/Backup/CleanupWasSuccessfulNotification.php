<?php

namespace App\Notifications\Backup;

use Spatie\Backup\Events\CleanupWasSuccessful;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification as BaseNotification;

class CleanupWasSuccessfulNotification extends BaseNotification
{
    public function __construct(CleanupWasSuccessful $event)
    {
        parent::__construct($event);
    }

    /**
     * @return list<string>
     */
    public function via(): array
    {
        // Success cleanup: mail only if explicitly enabled (same gate).
        return BackupAlertChannels::mailWhenConfigured();
    }
}
