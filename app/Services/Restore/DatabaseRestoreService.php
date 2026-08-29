<?php

namespace App\Services\Restore;

use App\Database\BackupArchiveInspector;
use App\Database\BackupConcurrencyGuard;
use App\Database\DatabaseSafetyGuard;
use RuntimeException;
use ZipArchive;

class DatabaseRestoreService
{
    public function __construct(
        private SqlDumpImporter $importer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function restore(
        string $backupFileName,
        ?string $explicitTarget,
        string $confirmationPhrase,
        bool $force = false,
    ): array {
        $started = microtime(true);

        RestoreAuditLogger::log('attempt', [
            'backup' => $backupFileName,
            'target' => $explicitTarget,
            'force_flag' => $force,
            'mode' => 'database',
        ]);

        DatabaseSafetyGuard::assertRestoreConfirmationPhrase($confirmationPhrase);
        $target = DatabaseSafetyGuard::assertExplicitRestoreTarget($explicitTarget);
        \App\Database\DatabaseAccountGuard::assertAccountForOperation(
            \App\Database\DatabaseAccountGuard::OPERATION_RESTORE,
        );

        // --force never bypasses DatabaseSafetyGuard.
        unset($force);

        return BackupConcurrencyGuard::runRestore(function () use ($backupFileName, $target, $started) {
            $zipPath = $this->resolveBackupPath($backupFileName);
            $inspection = BackupArchiveInspector::inspect($zipPath);

            if (! ($inspection['readable'] ?? false)) {
                RestoreAuditLogger::log('rejected', ['reason' => 'unreadable_archive', 'target' => $target, 'backup' => $backupFileName]);
                throw new RuntimeException('Backup archive is unreadable.');
            }

            if (! ($inspection['sql']['present'] ?? false)) {
                RestoreAuditLogger::log('rejected', ['reason' => 'no_sql', 'target' => $target, 'backup' => $backupFileName]);
                throw new RuntimeException('Backup has no SQL dump.');
            }

            $sqlPath = $this->extractSqlOnly($zipPath);

            try {
                $this->importer->import($sqlPath, $target);
            } finally {
                if (is_file($sqlPath)) {
                    unlink($sqlPath);
                }
            }

            $durationMs = (int) round((microtime(true) - $started) * 1000);

            $report = [
                'mode' => 'database',
                'backup' => $backupFileName,
                'target' => $target,
                'sha256' => $inspection['sha256'] ?? null,
                'files_touched' => false,
                'application_files_restore_invoked' => false,
                'duration_ms' => $durationMs,
                'inspection_verdict' => $inspection['verdict'] ?? null,
                'status' => 'imported',
            ];

            RestoreAuditLogger::log('success', [
                'backup' => $backupFileName,
                'target' => $target,
                'duration_ms' => $durationMs,
                'sha256' => $inspection['sha256'] ?? null,
            ]);

            return $report;
        });
    }

    private function resolveBackupPath(string $backupFileName): string
    {
        $backupFileName = basename(str_replace(['\\', '..'], '', $backupFileName));
        $disk = config('backup.backup.destination.disks')[0] ?? 'local';
        $folder = config('backup.backup.name', 'laravel-backup');
        $relative = $folder.'/'.$backupFileName;

        $diskRoot = config("filesystems.disks.{$disk}.root");
        if (is_string($diskRoot) && $diskRoot !== '') {
            $path = $diskRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (is_file($path)) {
                return $path;
            }
        }

        throw new RuntimeException("Backup not found: {$backupFileName}");
    }

    private function extractSqlOnly(string $zipPath): string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Unable to open backup ZIP.');
        }

        $sqlName = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with(strtolower($name), '.sql')) {
                $sqlName = $name;
                break;
            }
        }

        if ($sqlName === null) {
            $zip->close();
            throw new RuntimeException('No SQL file inside archive.');
        }

        $contents = $zip->getFromName($sqlName);
        $zip->close();

        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('SQL dump is empty or corrupted.');
        }

        $tempDir = storage_path('app/restore-temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $sqlPath = $tempDir.DIRECTORY_SEPARATOR.'db-only-'.uniqid('', true).'.sql';
        file_put_contents($sqlPath, $contents);

        return $sqlPath;
    }
}
