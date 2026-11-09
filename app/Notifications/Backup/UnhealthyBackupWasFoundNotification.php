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
     * @return list<string>
     */
    public function via(): array
    {
        $backupDestinationStatus = $this->event->backupDestinationStatus;
        $backupDestination = $backupDestinationStatus->backupDestination();

        $context = [
            'message' => 'unhealthy_backup_was_found',
            'disk' => $backupDestination->diskName(),
            'backup_name' => $backupDestination->backupName(),
            'health_check' => null,
            'exception_class' => null,
            'exception_message' => null,
        ];

        $healthCheckFailure = $backupDestinationStatus->getHealthCheckFailure();
        if ($healthCheckFailure !== null) {
            $context['health_check'] = $healthCheckFailure->healthCheck()::class;

            $exception = $healthCheckFailure->exception();
            $context['exception_class'] = $exception::class;
            $context['exception_message'] = $exception->getMessage();
        }

        \Log::warning('backup.unhealthy', $context);

        return BackupAlertChannels::mailWhenConfigured();
    }
}
