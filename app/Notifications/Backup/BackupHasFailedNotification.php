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
     * Get the notification's delivery channels.
     * Retourner un tableau vide pour désactiver complètement les notifications
     */
    public function via(): array
    {
        return []; // Ne pas envoyer de notifications
    }
}

