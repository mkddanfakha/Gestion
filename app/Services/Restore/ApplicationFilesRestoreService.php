<?php

namespace App\Services\Restore;

use App\Database\BackupConcurrencyGuard;
use RuntimeException;

/**
 * Isolated application-file restore. Must never be invoked from DatabaseRestoreService.
 */
class ApplicationFilesRestoreService
{
    public static int $invokeCount = 0;

    /**
     * @return array<string, mixed>
     */
    public function restore(string $backupFileName, string $confirmationPhrase, bool $dryRun = true): array
    {
        self::$invokeCount++;

        RestoreAuditLogger::log('files_attempt', [
            'type' => 'files',
            'backup' => $backupFileName,
            'dry_run' => $dryRun,
        ]);

        if ($confirmationPhrase !== 'FILES_RESTORE') {
            throw new RuntimeException(
                "DATABASE SAFETY BLOCK\nApplication file restore requires confirmation_phrase=FILES_RESTORE.",
            );
        }

        return BackupConcurrencyGuard::runRestore(function () use ($backupFileName, $dryRun) {
            if ($dryRun) {
                RestoreAuditLogger::log('files_dry_run', ['backup' => $backupFileName]);

                return [
                    'mode' => 'files',
                    'backup' => $backupFileName,
                    'dry_run' => true,
                    'files_written' => false,
                    'status' => 'dry_run',
                ];
            }

            throw new RuntimeException(
                "DATABASE SAFETY BLOCK\nLive application file restore is disabled in PRE-PROD. Use dry-run only.",
            );
        });
    }
}
