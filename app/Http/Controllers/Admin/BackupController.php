<?php

namespace App\Http\Controllers\Admin;

use App\Database\BackupArchiveInspector;
use App\Database\BackupConcurrencyGuard;
use App\Database\DatabaseSafetyGuard;
use App\Database\ProtectedDatabaseException;
use App\Http\Controllers\Controller;
use App\Services\Backup\BackupAuditService;
use App\Services\Backup\BackupCreationProgress;
use App\Services\Backup\BackupCreationService;
use App\Services\Backup\BackupImportService;
use App\Services\Backup\BackupManifestService;
use App\Services\Backup\BackupMetadataService;
use App\Services\Backup\BackupPathGuard;
use App\Services\Restore\ApplicationFilesRestoreService;
use App\Services\Restore\DatabaseRestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class BackupController extends Controller
{
    /**
     * Afficher la liste des sauvegardes
     */
    public function index()
    {
        $this->checkPermission(request(), 'backups', 'view');

        $backups = $this->getBackups();
        $latest = $backups[0] ?? null;

        return Inertia::render('Admin/Backups/Index', [
            'backups' => $backups,
            'disk' => BackupPathGuard::backupDiskName(),
            'restore_allowed_databases' => DatabaseSafetyGuard::restoreAllowedDatabases(),
            'protected_database' => DatabaseSafetyGuard::protectedDatabases()[0] ?? 'gestion',
            'operations_busy' => BackupConcurrencyGuard::isBackupLocked()
                || BackupConcurrencyGuard::isRestoreLocked(),
            'summary' => [
                'count' => count($backups),
                'last_backup_at' => $latest['date'] ?? null,
                'last_backup_type' => $latest['type'] ?? null,
                'last_backup_status' => $latest['status'] ?? null,
                'is_busy' => BackupConcurrencyGuard::isBackupLocked()
                    || BackupConcurrencyGuard::isRestoreLocked(),
            ],
        ]);
    }

    /**
     * Créer une nouvelle sauvegarde (async via CreateBackupJob).
     *
     * Lock ownership: ONLY backup:production (RunProductionBackupCommand).
     * Do not wrap the job / Artisan::call with BackupConcurrencyGuard here.
     */
    public function store(Request $request, BackupCreationService $creation)
    {
        $this->checkPermission(request(), 'backups', 'create');

        try {
            $onlyDb = $request->boolean('only_db');
            $user = $request->user();
            if ($user === null) {
                abort(403);
            }

            Log::info('backup.ui.create.queued', [
                'only_db' => $onlyDb,
                'user_id' => $user->id,
            ]);

            $result = $creation->start($onlyDb, $user);

            return redirect()->route('admin.backups.index')
                ->with('success', 'Création de sauvegarde démarrée. Le suivi s\'affiche ci-dessous.')
                ->with('backup_job_id', $result['job_id']);
        } catch (RuntimeException $e) {
            if (str_contains(strtolower($e->getMessage()), 'already in progress')) {
                return redirect()->route('admin.backups.index')
                    ->with('error', 'Une opération de sauvegarde est déjà en cours. Veuillez patienter.');
            }

            Log::error('backup.ui.create.exception', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return redirect()->route('admin.backups.index')
                ->with('error', 'La sauvegarde n\'a pas pu être créée. Veuillez réessayer.');
        } catch (Throwable $e) {
            Log::error('backup.ui.create.exception', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return redirect()->route('admin.backups.index')
                ->with('error', 'La sauvegarde n\'a pas pu être créée. Veuillez réessayer.');
        }
    }

    /**
     * JSON progress for an async backup creation job.
     */
    public function createStatus(Request $request, string $jobId)
    {
        $this->checkPermission(request(), 'backups', 'create');

        $progress = BackupCreationProgress::get($jobId);
        if ($progress === null) {
            return response()->json([
                'status' => 'unknown',
                'percentage' => 0,
                'message' => 'Aucune de création introuvable ou expirée.',
                'job_id' => $jobId,
            ], 404);
        }

        $ownerId = (int) ($progress['user_id'] ?? 0);
        if ($ownerId > 0 && $ownerId !== (int) $request->user()?->id) {
            abort(403, 'Cette action de création ne vous appartient pas.');
        }

        return response()->json([
            'job_id' => $progress['job_id'] ?? $jobId,
            'status' => $progress['status'] ?? 'unknown',
            'percentage' => (int) ($progress['percentage'] ?? 0),
            'message' => (string) ($progress['message'] ?? ''),
            'only_db' => (bool) ($progress['only_db'] ?? false),
            'filename' => $progress['filename'] ?? null,
            'updated_at' => $progress['updated_at'] ?? null,
        ]);
    }

    /**
     * Télécharger une sauvegarde
     */
    public function download(Request $request, string $backup): StreamedResponse|\Illuminate\Http\RedirectResponse
    {
        $this->checkPermission(request(), 'backups', 'download');

        try {
            $absolute = BackupPathGuard::resolveExistingBackupPath($backup);
            $relative = BackupPathGuard::relativePathFromAbsolute($absolute);
            $disk = BackupPathGuard::backupDiskName();

            return Storage::disk($disk)->download($relative, basename($absolute));
        } catch (RuntimeException $e) {
            return redirect()->route('admin.backups.index')
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Supprimer une sauvegarde
     */
    public function destroy(Request $request, string $backup)
    {
        $this->checkPermission(request(), 'backups', 'delete');

        try {
            if (BackupConcurrencyGuard::isRestoreLocked() || BackupConcurrencyGuard::isBackupLocked()) {
                return redirect()->route('admin.backups.index')
                    ->with('error', 'Impossible de supprimer une sauvegarde pendant une opération backup/restore.');
            }

            $absolute = BackupPathGuard::resolveExistingBackupPath($backup);
            $safeName = BackupPathGuard::sanitizeBackupFileName($backup);
            if (! @unlink($absolute)) {
                return redirect()->route('admin.backups.index')
                    ->with('error', 'Impossible de supprimer la sauvegarde.');
            }

            BackupMetadataService::deleteForZip($safeName);

            return redirect()->route('admin.backups.index')
                ->with('success', 'Sauvegarde supprimée avec succès.');
        } catch (RuntimeException $e) {
            return redirect()->route('admin.backups.index')
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Importer un ZIP de sauvegarde (ne restaure pas).
     */
    public function import(Request $request, BackupImportService $importService)
    {
        $this->checkPermission(request(), 'backups', 'create');

        ini_set('max_execution_time', '600');
        ini_set('memory_limit', '512M');

        if (! $request->hasFile('backup_file')) {
            return redirect()->route('admin.backups.index')
                ->with('error', 'Veuillez sélectionner un fichier zip de sauvegarde.');
        }

        $originalName = $request->file('backup_file')?->getClientOriginalName();

        try {
            $result = $importService->import($request->file('backup_file'));

            $preview = [
                'filename' => $result['filename'],
                'type' => BackupMetadataService::uiTypeFromMetaType($result['type'] ?? null) ?? 'database',
                'status' => 'imported',
                'size_bytes' => $result['size'] ?? null,
                'size' => $this->formatBytes((int) ($result['size'] ?? 0)),
                'sha256' => $result['sha256'] ?? null,
                'restored' => false,
            ];

            return redirect()->route('admin.backups.index')
                ->with('success', 'Sauvegarde importée avec succès. Aucune restauration n\'a été effectuée.')
                ->with('import_preview', $preview);
        } catch (RuntimeException $e) {
            Log::warning('backup.import.rejected', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            BackupAuditService::importRejected($e->getMessage(), $originalName);

            return redirect()->route('admin.backups.index')
                ->with('error', $this->friendlyImportErrorMessage($e->getMessage()));
        } catch (Throwable $e) {
            Log::error('backup.import.exception', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            BackupAuditService::importRejected('unexpected_error', $originalName);

            return redirect()->route('admin.backups.index')
                ->with('error', 'L\'import de la sauvegarde a échoué. Veuillez réessayer.');
        }
    }

    /**
     * Read-only inspection preview for restore UX (no restore performed).
     */
    public function inspect(Request $request, string $backup)
    {
        $this->checkPermission(request(), 'backups', 'restore');

        try {
            $absolute = BackupPathGuard::resolveExistingBackupPath($backup);
            $inspection = BackupArchiveInspector::inspect($absolute);
            $meta = BackupMetadataService::readForZip(basename($absolute));

            $readable = ($inspection['readable'] ?? false) === true && empty($inspection['error']);
            $sqlPresent = (bool) ($inspection['sql']['present'] ?? false);
            $verdict = (string) ($inspection['verdict'] ?? '');

            $canRestore = $readable && $sqlPresent
                && ! in_array($verdict, ['INVALID_TOO_SMALL', 'INVALID_NO_SQL'], true);

            $integrity = app(BackupManifestService::class)->verifyIntegrity(basename($absolute));

            // Soft gate: integrity INVALID does not bypass allow-list later, but blocks "Continuer" UX.
            if (($integrity['result'] ?? null) === \App\Services\Backup\BackupManifest::INTEGRITY_INVALID) {
                $canRestore = false;
            }

            return response()->json([
                'filename' => basename($absolute),
                'readable' => $readable,
                'can_restore' => $canRestore,
                'size_bytes' => $inspection['size_bytes'] ?? null,
                'sha256' => $inspection['sha256'] ?? null,
                'verdict' => $verdict !== '' ? $verdict : null,
                'sql_present' => $sqlPresent,
                'sql_file_count' => count($inspection['sql']['files'] ?? []),
                'business_inserts' => (int) ($inspection['sql']['business_inserts'] ?? 0),
                'looks_like_schema_only' => (bool) ($inspection['sql']['looks_like_schema_only'] ?? false),
                'has_attachments_paths' => (bool) ($inspection['has_attachments_paths'] ?? false),
                'contains_dotenv' => (bool) ($inspection['contains_dotenv'] ?? false),
                'type' => BackupMetadataService::uiTypeFromMetaType($meta['type'] ?? null)
                    ?? (($inspection['has_attachments_paths'] ?? false) ? 'full' : 'database'),
                'source' => isset($meta['source']) && is_string($meta['source']) ? $meta['source'] : null,
                'status' => BackupMetadataService::uiStatusFromMeta($meta, $canRestore ? 'valid' : 'invalid'),
                'integrity' => $integrity['result'] ?? null,
                'compatibility' => $integrity['compatibility'] ?? null,
                'integrity_message' => $integrity['message'] ?? null,
                'protected_database' => DatabaseSafetyGuard::protectedDatabases()[0] ?? 'gestion',
                'restore_allowed_databases' => DatabaseSafetyGuard::restoreAllowedDatabases(),
                'mode' => 'database',
                'files_will_be_modified' => false,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'readable' => false,
                'can_restore' => false,
                'message' => 'Impossible d\'inspecter cette sauvegarde.',
            ], 404);
        }
    }

    /**
     * Verify archive integrity (read-only: no restore, no backup:production).
     */
    public function verifyIntegrity(Request $request, string $backup, BackupManifestService $manifests)
    {
        $this->checkPermission(request(), 'backups', 'view');

        try {
            $result = $manifests->verifyIntegrity($backup);
            $manifest = $result['manifest'] ?? null;

            BackupAuditService::integrityChecked([
                'filename' => $result['filename'] ?? $backup,
                'backup_id' => is_array($manifest) ? ($manifest['backup_id'] ?? null) : null,
                'result' => $result['result'] ?? null,
                'type' => is_array($manifest) ? ($manifest['type'] ?? null) : null,
                'source' => is_array($manifest) ? ($manifest['source'] ?? null) : null,
                'compatibility' => $result['compatibility'] ?? null,
            ]);

            return response()->json($result);
        } catch (RuntimeException $e) {
            return response()->json([
                'result' => 'MISSING',
                'integrity' => 'MISSING',
                'message' => 'Impossible de vérifier cette sauvegarde.',
            ], 404);
        }
    }

    /**
     * Restaurer une sauvegarde (DB-only, allow-list).
     *
     * Optional safety_backup: runs backup:production --only-db first (app DB snapshot),
     * then restores into allow-listed target only. Never targets gestion.
     */
    public function restore(Request $request, string $backup, DatabaseRestoreService $restore, BackupCreationService $creation)
    {
        $this->checkPermission(request(), 'backups', 'restore');

        $request->validate([
            'confirm' => 'required|accepted',
            'confirmation_phrase' => 'required|string',
            'target_database' => 'required|string',
            'restore_mode' => 'required|in:database',
            'safety_backup' => 'sometimes|boolean',
            'acknowledge_overwrite' => 'required|accepted',
        ], [
            'confirm.accepted' => 'Vous devez confirmer la restauration.',
            'confirmation_phrase.required' => 'La phrase de confirmation serveur est obligatoire.',
            'target_database.required' => 'La base cible explicite est obligatoire.',
            'restore_mode.in' => 'Seul le mode database (DB-only) est autorisé sur cette route.',
            'acknowledge_overwrite.accepted' => 'Vous devez confirmer le remplacement des données de la base cible.',
        ]);

        $safeName = BackupPathGuard::sanitizeBackupFileName($backup);
        $target = (string) $request->input('target_database');
        $wantSafety = $request->boolean('safety_backup');
        $safetyFilename = null;

        try {
            BackupPathGuard::resolveExistingBackupPath($backup);

            if ($wantSafety) {
                if (BackupConcurrencyGuard::isBackupLocked() || BackupConcurrencyGuard::isRestoreLocked()) {
                    return redirect()->route('admin.backups.index')
                        ->with('error', 'Une opération backup/restore est déjà en cours. Réessayez plus tard.');
                }

                Log::info('backup.ui.restore.safety.start', [
                    'backup' => $safeName,
                    'target' => $target,
                    'user_id' => $request->user()?->id,
                ]);

                $started = time();
                $exitCode = Artisan::call('backup:production', ['--only-db' => true]);
                if ($exitCode !== 0) {
                    BackupAuditService::restoreFailed('safety_backup_failed', $safeName, $target);

                    return redirect()->route('admin.backups.index')
                        ->with('error', 'La sauvegarde de sécurité a échoué. La restauration a été annulée.');
                }

                $meta = $creation->attachManualMetadata(true, $request->user()?->id, $started);
                $safetyFilename = is_array($meta) ? ($meta['filename'] ?? null) : null;
            }

            $report = $restore->restore(
                $safeName,
                $target,
                (string) $request->input('confirmation_phrase'),
                false,
            );

            BackupAuditService::restored([
                'filename' => $safeName,
                'target' => $report['target'] ?? $target,
                'sha256' => $report['sha256'] ?? null,
                'safety_backup' => $wantSafety,
                'safety_filename' => $safetyFilename,
            ]);

            $message = 'Restauration DB-only vers '.($report['target'] ?? $target).' terminée. Aucun fichier applicatif n\'a été modifié.';
            if ($wantSafety && $safetyFilename) {
                $message .= ' Sauvegarde de sécurité créée : '.$safetyFilename.'.';
            }

            return redirect()->route('admin.backups.index')
                ->with('success', $message);
        } catch (ProtectedDatabaseException $e) {
            BackupAuditService::restoreFailed($e->getMessage(), $safeName, $target);
            abort(403, $e->getMessage());
        } catch (RuntimeException $e) {
            BackupAuditService::restoreFailed($e->getMessage(), $safeName, $target);

            if (str_contains($e->getMessage(), 'already in progress')) {
                abort(409, $e->getMessage());
            }

            return redirect()->route('admin.backups.index')
                ->with('error', $this->friendlyRestoreErrorMessage($e->getMessage()));
        } catch (Throwable $e) {
            BackupAuditService::restoreFailed($e->getMessage(), $safeName, $target);
            Log::error('backup.ui.restore.exception', [
                'message' => $e->getMessage(),
                'backup' => $safeName,
                'user_id' => $request->user()?->id,
            ]);

            return redirect()->route('admin.backups.index')
                ->with('error', 'La restauration a échoué. Vérifiez la cible et réessayez.');
        }
    }

    public function restoreApplicationFiles(Request $request, string $backup, ApplicationFilesRestoreService $files)
    {
        $this->checkPermission(request(), 'backups', 'restore_files');

        $request->validate([
            'confirmation_phrase' => 'required|string',
            'dry_run' => 'required|accepted',
        ]);

        try {
            BackupPathGuard::resolveExistingBackupPath($backup);

            $report = $files->restore(
                BackupPathGuard::sanitizeBackupFileName($backup),
                (string) $request->input('confirmation_phrase'),
                true,
            );

            return redirect()->route('admin.backups.index')
                ->with('success', 'File restore dry-run only: '.json_encode($report));
        } catch (ProtectedDatabaseException|RuntimeException $e) {
            abort(403, $e->getMessage());
        }
    }

    /**
     * @return list<array{
     *   name: string,
     *   path: string,
     *   size: string,
     *   size_bytes: int,
     *   date: string,
     *   timestamp: int,
     *   type: string,
     *   status: string
     * }>
     */
    private function getBackups(): array
    {
        try {
            $directory = BackupPathGuard::backupDirectoryAbsolutePath();
        } catch (RuntimeException) {
            return [];
        }

        $diskRoot = BackupPathGuard::normalizeSeparators(
            rtrim(Storage::disk(BackupPathGuard::backupDiskName())->path(''), DIRECTORY_SEPARATOR),
        );

        $backups = [];

        try {
            $iterator = new \DirectoryIterator($directory);
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isDot() || ! $fileInfo->isFile()) {
                    continue;
                }
                if (strtolower($fileInfo->getExtension()) !== 'zip') {
                    continue;
                }

                $absolute = BackupPathGuard::normalizeSeparators($fileInfo->getPathname());
                if (! BackupPathGuard::isPathInsideDirectory($absolute, $directory)) {
                    continue;
                }

                $relative = ltrim(substr($absolute, strlen($diskRoot)), DIRECTORY_SEPARATOR);
                $relative = str_replace('\\', '/', $relative);

                $metaPeek = $this->classifyArchiveMetadata($absolute);
                $meta = BackupMetadataService::readForZip($fileInfo->getFilename());
                $sizeBytes = (int) $fileInfo->getSize();
                $integrityHint = app(BackupManifestService::class)->listingIntegrityHint(
                    $fileInfo->getFilename(),
                    $meta,
                );

                $type = BackupMetadataService::uiTypeFromMetaType($meta['type'] ?? null)
                    ?? $metaPeek['type'];
                $status = BackupMetadataService::uiStatusFromMeta($meta, $metaPeek['status']);
                $source = isset($meta['source']) && is_string($meta['source'])
                    ? strtolower($meta['source'])
                    : null;

                $sha256 = null;
                if (isset($meta['archive']['sha256']) && is_string($meta['archive']['sha256'])) {
                    $sha256 = $meta['archive']['sha256'];
                } elseif (isset($meta['sha256']) && is_string($meta['sha256'])) {
                    $sha256 = $meta['sha256'];
                } elseif (isset($integrityHint['sha256']) && is_string($integrityHint['sha256'])) {
                    $sha256 = $integrityHint['sha256'];
                }

                $backups[] = [
                    'name' => $fileInfo->getFilename(),
                    'path' => $relative,
                    'size' => $this->formatBytes($sizeBytes),
                    'size_bytes' => $sizeBytes,
                    'date' => date('Y-m-d H:i:s', $fileInfo->getMTime()),
                    'timestamp' => $fileInfo->getMTime(),
                    'type' => $type,
                    'status' => $status,
                    'source' => $source,
                    'sha256' => $sha256,
                    'integrity' => $integrityHint['integrity'] ?? 'unknown',
                    'compatibility' => $integrityHint['compatibility'] ?? null,
                    'manifest_version' => $integrityHint['manifest_version'] ?? null,
                ];
            }
        } catch (Throwable $e) {
            Log::error('backup.list.failed', ['message' => $e->getMessage()]);

            return [];
        }

        usort($backups, fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        return $backups;
    }

    /**
     * Lightweight ZIP peek (entry names only — no SQL content read).
     *
     * @return array{type: string, status: string}
     */
    private function classifyArchiveMetadata(string $absolutePath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            return ['type' => 'unknown', 'status' => 'invalid'];
        }

        $hasSql = false;
        $hasNonDbContent = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (! is_string($name) || $name === '') {
                continue;
            }

            $normalized = str_replace('\\', '/', $name);
            if (str_ends_with($normalized, '/')) {
                continue;
            }

            if (str_ends_with(strtolower($normalized), '.sql')) {
                $hasSql = true;

                continue;
            }

            if (str_starts_with($normalized, 'db-dumps/')) {
                continue;
            }

            $hasNonDbContent = true;
        }

        $zip->close();

        if (! $hasSql) {
            return ['type' => 'unknown', 'status' => 'invalid'];
        }

        return [
            'type' => $hasNonDbContent ? 'full' : 'database',
            'status' => 'valid',
        ];
    }

    private function friendlyImportErrorMessage(string $technical): string
    {
        $map = [
            'missing database dump' => 'Cette archive n\'est pas une sauvegarde MKD-Pro valide.',
            'not a valid MKD-Pro' => 'Cette archive n\'est pas une sauvegarde MKD-Pro valide.',
            'must be a .zip' => 'Le fichier doit être une archive .zip.',
            'exceeds the maximum' => 'Le fichier est trop volumineux (maximum 10 Go).',
            'empty or missing' => 'L\'archive est vide ou illisible.',
            'unreadable or corrupted' => 'L\'archive est illisible ou corrompue.',
            'path traversal' => 'Cette archive a été refusée pour des raisons de sécurité.',
            'symlink' => 'Cette archive a été refusée pour des raisons de sécurité.',
            'absolute path' => 'Cette archive a été refusée pour des raisons de sécurité.',
        ];

        foreach ($map as $needle => $friendly) {
            if (stripos($technical, $needle) !== false) {
                return $friendly;
            }
        }

        return 'L\'import de la sauvegarde a échoué. Veuillez vérifier le fichier et réessayer.';
    }

    private function friendlyRestoreErrorMessage(string $technical): string
    {
        $map = [
            'unreadable' => 'Cette archive est illisible et ne peut pas être restaurée.',
            'no SQL' => 'Cette archive ne contient pas de dump de base de données.',
            'Backup not found' => 'Sauvegarde introuvable.',
            'already in progress' => 'Une restauration est déjà en cours. Veuillez patienter.',
            'confirmation phrase' => 'La phrase de confirmation est incorrecte.',
            'not allow-listed' => 'La base cible n\'est pas autorisée.',
            'DATABASE SAFETY BLOCK' => 'La base cible n\'est pas autorisée pour la restauration.',
        ];

        foreach ($map as $needle => $friendly) {
            if (stripos($technical, $needle) !== false) {
                return $friendly;
            }
        }

        return 'La restauration a échoué. Vérifiez la cible et réessayez.';
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['o', 'Ko', 'Mo', 'Go', 'To'];

        $value = (float) max($bytes, 0);
        for ($i = 0; $value >= 1024 && $i < count($units) - 1; $i++) {
            $value /= 1024;
        }

        return round($value, $precision).' '.$units[$i];
    }
}
