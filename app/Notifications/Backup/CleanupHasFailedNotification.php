<?php

namespace App\Notifications\Backup;

use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification as BaseNotification;

class CleanupHasFailedNotification extends BaseNotification
{
    public function __construct(CleanupHasFailed $event)
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
