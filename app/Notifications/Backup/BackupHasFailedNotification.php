<?php

namespace App\Notifications\Backup;

use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification as BaseNotification;

class BackupHasFailedNotification extends BaseNotification
{
    public function __construct(BackupHasFailed $event)
    {
        parent::__construct($event);
    }

    /**
     * @return list<string>
     */
    public function via(): array
    {
        \Log::error('backup.failed', [
            'message' => property_exists($this, 'event') && isset($this->event->exception)
                ? $this->event->exception?->getMessage()
                : 'backup_failed',
        ]);

        return BackupAlertChannels::mailWhenConfigured();
    }
}
