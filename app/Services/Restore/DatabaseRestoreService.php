<?php

namespace App\Services\Restore;

use App\Database\BackupArchiveInspector;
use App\Database\BackupConcurrencyGuard;
use App\Database\DatabaseAccountGuard;
use App\Database\DatabaseSafetyGuard;
use App\Database\PrivilegedProcessRunner;
use App\Database\PrivilegedRestoreProcessRunner;
use App\Services\Backup\BackupManifest;
use App\Services\Backup\BackupManifestService;
use App\Services\Backup\BackupPathGuard;
use RuntimeException;
use ZipArchive;

class DatabaseRestoreService
{
    public function __construct(
        private SqlDumpImporter $importer,
        private PrivilegedRestoreProcessRunner $privilegedRestoreRunner,
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

        // --force never bypasses DatabaseSafetyGuard.
        unset($force);

        if (DatabaseAccountGuard::isRestoreSubprocess()) {
            DatabaseAccountGuard::assertAccountForOperation(
                DatabaseAccountGuard::OPERATION_RESTORE,
            );

            return $this->executeRestoreImport($backupFileName, $target, $started);
        }

        $this->assertBackupReadyForPrivilegedRestore($backupFileName);

        return $this->restoreViaPrivilegedSubprocess(
            $backupFileName,
            $target,
            $confirmationPhrase,
            $started,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function restoreViaPrivilegedSubprocess(
        string $backupFileName,
        string $target,
        string $confirmationPhrase,
        float $started,
    ): array {
        try {
            $result = $this->privilegedRestoreRunner->runRestore(
                $backupFileName,
                $target,
                $confirmationPhrase,
            );
        } catch (RuntimeException $e) {
            throw $this->mapCredentialRuntimeException($e);
        }

        if (! $result->successful()) {
            $message = trim(app(RestoreJobOutputSanitizer::class)->sanitize($result->combinedOutput()));
            if ($message === '') {
                $message = 'Privileged restore subprocess failed.';
            }

            throw new BackupRestoreRejectedException(
                BackupRestoreRejectedException::PROCESS_FAILED,
                $message,
            );
        }

        $report = $this->parseSubprocessReport($result->output);
        if ($report !== null) {
            return $report;
        }

        $zipPath = BackupPathGuard::resolveExistingBackupPath($backupFileName);
        $inspection = BackupArchiveInspector::inspect($zipPath);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        return [
            'mode' => 'database',
            'backup' => BackupPathGuard::sanitizeBackupFileName($backupFileName),
            'target' => $target,
            'sha256' => $inspection['sha256'] ?? null,
            'files_touched' => false,
            'application_files_restore_invoked' => false,
            'duration_ms' => $durationMs,
            'inspection_verdict' => $inspection['verdict'] ?? null,
            'status' => 'imported',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeRestoreImport(string $backupFileName, string $target, float $started): array
    {
        return BackupConcurrencyGuard::runRestore(function () use ($backupFileName, $target, $started) {
            $zipPath = $this->resolveBackupPath($backupFileName);
            $inspection = BackupArchiveInspector::inspect($zipPath);

            if (! ($inspection['readable'] ?? false)) {
                RestoreAuditLogger::log('rejected', ['reason' => 'unreadable_archive', 'target' => $target, 'backup' => $backupFileName]);
                throw new BackupRestoreRejectedException(
                    BackupRestoreRejectedException::BACKUP_INVALID,
                    'Backup archive is unreadable.',
                );
            }

            if (! ($inspection['sql']['present'] ?? false)) {
                RestoreAuditLogger::log('rejected', ['reason' => 'no_sql', 'target' => $target, 'backup' => $backupFileName]);
                throw new BackupRestoreRejectedException(
                    BackupRestoreRejectedException::SQL_NOT_FOUND,
                    'Backup has no SQL dump.',
                );
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

    private function assertBackupReadyForPrivilegedRestore(string $backupFileName): void
    {
        try {
            $zipPath = BackupPathGuard::resolveExistingBackupPath($backupFileName);
        } catch (RuntimeException $e) {
            throw new BackupRestoreRejectedException(
                BackupRestoreRejectedException::BACKUP_NOT_FOUND,
                $e->getMessage(),
                $e,
            );
        }
        $inspection = BackupArchiveInspector::inspect($zipPath);

        if (! ($inspection['readable'] ?? false)) {
            throw new BackupRestoreRejectedException(
                BackupRestoreRejectedException::BACKUP_INVALID,
                'Backup archive is unreadable.',
            );
        }

        if (! ($inspection['sql']['present'] ?? false)) {
            throw new BackupRestoreRejectedException(
                BackupRestoreRejectedException::SQL_NOT_FOUND,
                'Backup has no SQL dump.',
            );
        }

        $integrity = app(BackupManifestService::class)->verifyIntegrity(basename($zipPath));

        if (($integrity['result'] ?? null) === BackupManifest::INTEGRITY_INVALID) {
            throw new BackupRestoreRejectedException(
                BackupRestoreRejectedException::INTEGRITY_FAILED,
                $integrity['message'] ?? 'Backup integrity check failed.',
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseSubprocessReport(string $output): ?array
    {
        $lines = array_reverse(array_filter(array_map('trim', explode("\n", $output))));

        foreach ($lines as $line) {
            if (! str_starts_with($line, '{')) {
                continue;
            }

            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['target'], $decoded['status'])) {
                return $decoded;
            }
        }

        return null;
    }

    private function resolveBackupPath(string $backupFileName): string
    {
        try {
            return BackupPathGuard::resolveExistingBackupPath($backupFileName);
        } catch (RuntimeException $e) {
            throw new BackupRestoreRejectedException(
                BackupRestoreRejectedException::BACKUP_NOT_FOUND,
                $e->getMessage(),
                $e,
            );
        }
    }

    private function extractSqlOnly(string $zipPath): string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new BackupRestoreRejectedException(
                BackupRestoreRejectedException::BACKUP_INVALID,
                'Unable to open backup ZIP.',
            );
        }

        $sqlName = $this->selectSqlEntryName($zip);

        if ($sqlName === null) {
            $zip->close();
            throw new BackupRestoreRejectedException(
                BackupRestoreRejectedException::SQL_NOT_FOUND,
                'No SQL file inside archive.',
            );
        }

        $contents = $zip->getFromName($sqlName);
        $zip->close();

        if (! is_string($contents) || $contents === '') {
            throw new BackupRestoreRejectedException(
                BackupRestoreRejectedException::SQL_INVALID,
                'SQL dump is empty or corrupted.',
            );
        }

        $tempDir = storage_path('app/restore-temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $sqlPath = $tempDir.DIRECTORY_SEPARATOR.'db-only-'.uniqid('', true).'.sql';
        file_put_contents($sqlPath, $contents);

        return $sqlPath;
    }

    /**
     * Prefer Spatie db-dumps/*.sql; otherwise largest .sql entry in the archive.
     */
    private function selectSqlEntryName(ZipArchive $zip): ?string
    {
        $candidates = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (! str_ends_with(strtolower($name), '.sql')) {
                continue;
            }

            $stat = $zip->statIndex($i);
            $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
            $candidates[] = ['name' => $name, 'size' => $size];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            $aDb = str_contains(strtolower($a['name']), 'db-dumps/');
            $bDb = str_contains(strtolower($b['name']), 'db-dumps/');
            if ($aDb !== $bDb) {
                return $bDb <=> $aDb;
            }

            return $b['size'] <=> $a['size'];
        });

        return $candidates[0]['name'];
    }

    private function mapCredentialRuntimeException(RuntimeException $e): BackupRestoreRejectedException|RuntimeException
    {
        $message = $e->getMessage();

        if (str_contains($message, 'restore credential file is missing')) {
            return BackupRestoreRejectedException::fromRuntime(
                BackupRestoreRejectedException::CREDENTIALS_MISSING,
                $e,
            );
        }

        if (str_contains($message, 'restore credential file must define')) {
            return BackupRestoreRejectedException::fromRuntime(
                BackupRestoreRejectedException::CREDENTIALS_INVALID,
                $e,
            );
        }

        return $e;
    }
}
