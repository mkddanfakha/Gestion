<?php

namespace App\Services\Backup;

use App\Services\ActivityLogger;
use Throwable;

/**
 * Activity audit for backup import/create (no secrets).
 */
final class BackupAuditService
{
    public static function integrityChecked(array $payload): void
    {
        try {
            ActivityLogger::log(
                'BACKUP_INTEGRITY_CHECKED',
                'backups',
                'Vérification d\'intégrité : '.($payload['filename'] ?? 'archive').' → '.($payload['result'] ?? ''),
                null,
                null,
                null,
                null,
                [
                    'filename' => $payload['filename'] ?? null,
                    'backup_id' => $payload['backup_id'] ?? null,
                    'result' => $payload['result'] ?? null,
                    'type' => $payload['type'] ?? null,
                    'source' => $payload['source'] ?? null,
                    'compatibility' => $payload['compatibility'] ?? null,
                ],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function created(array $payload): void
    {
        try {
            ActivityLogger::log(
                'BACKUP_CREATED',
                'backups',
                'Sauvegarde créée : '.($payload['filename'] ?? 'archive'),
                null,
                null,
                null,
                null,
                [
                    'filename' => $payload['filename'] ?? null,
                    'type' => $payload['type'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'sha256' => $payload['sha256'] ?? null,
                    'size_bytes' => $payload['size_bytes'] ?? null,
                    'source' => $payload['source'] ?? BackupMetadataService::SOURCE_MANUAL,
                    'backup_id' => $payload['backup_id'] ?? null,
                    'user_id' => $payload['user_id'] ?? null,
                ],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function restored(array $payload): void
    {
        try {
            ActivityLogger::log(
                'BACKUP_RESTORED',
                'backups',
                'Sauvegarde restaurée (DB-only) : '.($payload['filename'] ?? 'archive').' → '.($payload['target'] ?? ''),
                null,
                null,
                null,
                null,
                [
                    'filename' => $payload['filename'] ?? null,
                    'target' => $payload['target'] ?? null,
                    'sha256' => $payload['sha256'] ?? null,
                    'safety_backup' => (bool) ($payload['safety_backup'] ?? false),
                    'safety_filename' => $payload['safety_filename'] ?? null,
                    'mode' => 'database',
                    'files_touched' => false,
                ],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function restoreFailed(string $reason, ?string $filename = null, ?string $target = null): void
    {
        try {
            ActivityLogger::log(
                'BACKUP_RESTORE_FAILED',
                'backups',
                'Échec de restauration DB-only'.($filename ? ' : '.$filename : ''),
                null,
                null,
                null,
                null,
                [
                    'filename' => $filename,
                    'target' => $target,
                    'reason' => mb_substr($reason, 0, 500),
                ],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function imported(array $payload): void
    {
        try {
            ActivityLogger::log(
                'BACKUP_IMPORTED',
                'backups',
                'Sauvegarde importée : '.($payload['filename'] ?? 'archive.zip'),
                null,
                null,
                null,
                null,
                [
                    'filename' => $payload['filename'] ?? null,
                    'type' => $payload['type'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'sha256' => $payload['sha256'] ?? null,
                    'size_bytes' => $payload['size_bytes'] ?? null,
                    'source' => BackupMetadataService::SOURCE_IMPORT,
                    'backup_id' => $payload['backup_id'] ?? null,
                ],
            );
        } catch (Throwable $e) {
            // Audit must not break import; details stay in application logs.
            report($e);
        }
    }

    public static function importRejected(string $reason, ?string $originalName = null): void
    {
        try {
            ActivityLogger::log(
                'BACKUP_IMPORT_REJECTED',
                'backups',
                'Import de sauvegarde refusé'.($originalName ? ' ('.$originalName.')' : ''),
                null,
                null,
                null,
                null,
                [
                    'reason' => mb_substr($reason, 0, 500),
                    'original_filename' => $originalName,
                ],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
