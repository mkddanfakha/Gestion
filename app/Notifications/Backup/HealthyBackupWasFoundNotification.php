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
     * Get the notification's delivery channels.
     * Retourner un tableau vide pour désactiver complètement les notifications
     */
    public function via(): array
    {
        return []; // Ne pas envoyer de notifications
    }
}

