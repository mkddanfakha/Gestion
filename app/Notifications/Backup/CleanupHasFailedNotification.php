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
     * Get the notification's delivery channels.
     * Retourner un tableau vide pour désactiver complètement les notifications
     */
    public function via(): array
    {
        return []; // Ne pas envoyer de notifications
    }
}

