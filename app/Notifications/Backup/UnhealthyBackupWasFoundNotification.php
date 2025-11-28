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
     * Get the notification's delivery channels.
     * Retourner un tableau vide pour désactiver complètement les notifications
     */
    public function via(): array
    {
        return []; // Ne pas envoyer de notifications
    }
}

