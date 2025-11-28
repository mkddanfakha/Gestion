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
     * Get the notification's delivery channels.
     * Retourner un tableau vide pour désactiver complètement les notifications
     */
    public function via(): array
    {
        return []; // Ne pas envoyer de notifications
    }
}

