<?php

namespace App\Http\Controllers\Admin;

use App\Database\BackupConcurrencyGuard;
use App\Database\DatabaseSafetyGuard;
use App\Database\ProtectedDatabaseException;
use App\Http\Controllers\Controller;
use App\Jobs\CreateBackupJob;
use App\Services\Restore\ApplicationFilesRestoreService;
use App\Services\Restore\DatabaseRestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Session;
use Spatie\Backup\BackupDestination\BackupDestination;
use Inertia\Inertia;

class BackupController extends Controller
{
    /**
     * Afficher la liste des sauvegardes
     */
    public function index()
    {
        $this->checkPermission(request(), 'backups', 'view');

        $backups = $this->getBackups();

        return Inertia::render('Admin/Backups/Index', [
            'backups' => $backups,
            'disk' => config('backup.backup.destination.disks')[0] ?? 'local',
            'restore_allowed_databases' => DatabaseSafetyGuard::restoreAllowedDatabases(),
            'protected_database' => DatabaseSafetyGuard::protectedDatabases()[0] ?? 'gestion',
        ]);
    }

    /**
     * Créer une nouvelle sauvegarde
     */
    public function store(Request $request)
    {
        \Log::info('BackupController@store appelé', [
            'method' => $request->method(),
            'only_db' => $request->input('only_db', false),
            'all_input' => $request->all()
        ]);
        
        $this->checkPermission(request(), 'backups', 'create');

        try {
            return BackupConcurrencyGuard::runBackup(function () use ($request) {
                return $this->executeBackupStore($request);
            });
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'already in progress')) {
                return redirect()->route('admin.backups.index')
                    ->with('error', $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Exécuter la sauvegarde (appelé sous lock).
     */
    private function executeBackupStore(Request $request)
    {
        \Log::info('BackupController@store appelé', [
            'method' => $request->method(),
            'only_db' => $request->input('only_db', false),
            'all_input' => $request->all()
        ]);

        try {
            $onlyDb = (bool) $request->input('only_db', false);

            set_time_limit(600);
            ini_set('max_execution_time', '600');
            ini_set('memory_limit', '512M');

            \Log::info('Début de la création de la sauvegarde via backup:production', [
                'only_db' => $onlyDb,
            ]);

            $exitCode = 0;
            $artisanOutput = '';

            if ($onlyDb) {
                $exitCode = Artisan::call('backup:production', ['--only-db' => true]);
            } else {
                $exitCode = Artisan::call('backup:production');
            }

            $artisanOutput = Artisan::output();

            \Log::info('Commande backup:production exécutée', [
                'exit_code' => $exitCode,
                'output_length' => strlen($artisanOutput),
            ]);

            if ($exitCode !== 0) {
                \Log::error('La commande backup:production a échoué', [
                    'exit_code' => $exitCode,
                    'output_preview' => substr($artisanOutput, 0, 500),
                ]);

                throw new \Exception('La commande de sauvegarde a échoué.');
            }

            \Log::info('Sauvegarde créée avec succès');

            sleep(2);

            return redirect()->route('admin.backups.index')
                ->with('success', 'Sauvegarde créée avec succès.');
        } catch (\Exception $e) {
            \Log::error('Erreur lors de la création de la sauvegarde', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return redirect()->route('admin.backups.index')
                ->with('error', 'Erreur lors de la création de la sauvegarde: ' . $e->getMessage());
        }
    }


    /**
     * Télécharger une sauvegarde
     */
    public function download(Request $request, string $backupName)
    {
        $this->checkPermission(request(), 'backups', 'download');

        $disk = config('backup.backup.destination.disks')[0] ?? 'local';
        $backupName = urldecode($backupName);
        $backupPath = $this->getBackupFolderName() . '/' . $backupName;

        if (!Storage::disk($disk)->exists($backupPath)) {
            return redirect()->route('admin.backups.index')
                ->with('error', 'Sauvegarde introuvable.');
        }

        return Storage::disk($disk)->download($backupPath);
    }

    /**
     * Supprimer une sauvegarde
     */
    public function destroy(Request $request, string $backupName)
    {
        $this->checkPermission(request(), 'backups', 'delete');

        $disk = config('backup.backup.destination.disks')[0] ?? 'local';
        $backupName = urldecode($backupName);
        $backupPath = $this->getBackupFolderName() . '/' . $backupName;

        if (!Storage::disk($disk)->exists($backupPath)) {
            return redirect()->route('admin.backups.index')
                ->with('error', 'Sauvegarde introuvable.');
        }

        Storage::disk($disk)->delete($backupPath);

        return redirect()->route('admin.backups.index')
            ->with('success', 'Sauvegarde supprimée avec succès.');
    }

    /**
     * ====================================================================
     * ⚠️  SECTION CRITIQUE - IMPORT DE SAUVEGARDE ⚠️
     * ====================================================================
     * 
     * ⚠️  ATTENTION : NE PAS SUPPRIMER CETTE SECTION ⚠️
     * 
     * Cette méthode est essentielle pour la fonctionnalité d'import
     * de fichiers zip de sauvegarde depuis l'interface utilisateur.
     * 
     * Fonctionnalités incluses :
     * - Validation du fichier uploadé (type, taille, erreurs)
     * - Création d'un fichier temporaire pour valider le zip
     * - Validation de l'intégrité du zip
     * - Vérification du contenu (dump DB ou fichiers)
     * - Protection contre les doublons
     * - Stockage sécurisé dans le dossier de sauvegardes
     * 
     * ⚠️  NE PAS MODIFIER OU SUPPRIMER SANS CONNAISSANCE ⚠️
     * ====================================================================
     * 
     * Importer un fichier zip de sauvegarde
     */
    public function import(Request $request)
    {
        $this->checkPermission(request(), 'backups', 'create');

        // Augmenter les limites pour permettre l'upload de gros fichiers
        ini_set('max_execution_time', 600);
        ini_set('memory_limit', '512M');

        \Log::info('Import de sauvegarde - Début', [
            'has_file' => $request->hasFile('backup_file'),
            'all_files' => array_keys($request->allFiles()),
            'all_input' => array_keys($request->all()),
            'content_type' => $request->header('Content-Type'),
            'method' => $request->method(),
        ]);

        // Vérifier que le fichier est présent
        if (!$request->hasFile('backup_file')) {
            // Essayer de récupérer le fichier d'une autre manière
            $allFiles = $request->allFiles();
            \Log::error('Import de sauvegarde - Fichier manquant', [
                'has_file' => $request->hasFile('backup_file'),
                'all_files_keys' => array_keys($allFiles),
                'all_files_count' => count($allFiles),
                'request_keys' => array_keys($request->all()),
            ]);
            
            // Si aucun fichier n'est trouvé, retourner une erreur
            if (empty($allFiles)) {
                return redirect()->route('admin.backups.index')
                    ->with('error', 'Veuillez sélectionner un fichier zip de sauvegarde. Aucun fichier n\'a été reçu.');
            }
            
            // Essayer de récupérer le premier fichier disponible
            $file = reset($allFiles);
            if (!$file) {
                return redirect()->route('admin.backups.index')
                    ->with('error', 'Le fichier uploadé n\'est pas valide.');
            }
        } else {
            $file = $request->file('backup_file');
        }
        
        // Logger les informations du fichier
        \Log::info('Import de sauvegarde - Fichier récupéré', [
            'file_name' => $file ? $file->getClientOriginalName() : 'null',
            'error' => $file ? $file->getError() : 'null',
            'error_message' => $file ? $file->getErrorMessage() : 'null',
        ]);
        
        // Vérifier que c'est bien un fichier
        if (!$file) {
            return redirect()->route('admin.backups.index')
                ->with('error', 'Le fichier uploadé n\'est pas valide.');
        }
        
        // Vérifier la taille du fichier
        try {
            $fileSize = $file->getSize();
            if ($fileSize === 0) {
                return redirect()->route('admin.backups.index')
                    ->with('error', 'Le fichier uploadé est vide.');
            }
        } catch (\Exception $e) {
            \Log::warning('Impossible de vérifier la taille du fichier (getSize)', [
                'error' => $e->getMessage(),
            ]);
        }
        
        // Vérifier l'erreur d'upload
        $uploadError = $file->getError();
        if ($uploadError !== UPLOAD_ERR_OK && $uploadError !== UPLOAD_ERR_NO_FILE) {
            \Log::error('Import de sauvegarde - Erreur d\'upload', [
                'error_code' => $uploadError,
                'error_message' => $file->getErrorMessage(),
            ]);
            return redirect()->route('admin.backups.index')
                ->with('error', 'Erreur lors de l\'upload du fichier: ' . $file->getErrorMessage());
        }

        // Vérifier l'extension
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension !== 'zip') {
            return redirect()->route('admin.backups.index')
                ->with('error', 'Le fichier doit être un fichier zip (.zip).');
        }

        // Vérifier la taille (10 GB = 10737418240 bytes)
        try {
            $fileSize = $file->getSize();
            $maxSize = 10 * 1024 * 1024 * 1024; // 10 GB en bytes
            if ($fileSize > $maxSize) {
                return redirect()->route('admin.backups.index')
                    ->with('error', 'Le fichier est trop volumineux. Taille maximale : 10 GB.');
            }
        } catch (\Exception $e) {
            \Log::warning('Impossible de vérifier la taille du fichier', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $disk = config('backup.backup.destination.disks')[0] ?? 'local';
            $backupName = $this->getBackupFolderName();
            $originalFilename = $file->getClientOriginalName();
            $backupPath = $backupName . '/' . $originalFilename;

            // Vérifier si un fichier avec le même nom existe déjà
            if (Storage::disk($disk)->exists($backupPath)) {
                return redirect()->route('admin.backups.index')
                    ->with('error', 'Un fichier de sauvegarde avec ce nom existe déjà. Veuillez renommer votre fichier ou supprimer l\'ancien.');
            }

            // Valider que le fichier est un zip valide
            $zip = new \ZipArchive();
            
            // Créer un fichier temporaire pour valider le zip
            $tempFile = tmpfile();
            if ($tempFile === false) {
                return redirect()->route('admin.backups.index')
                    ->with('error', 'Impossible de créer un fichier temporaire. Veuillez réessayer.');
            }
            
            $tempPath = stream_get_meta_data($tempFile)['uri'];
            
            // Lire le contenu du fichier uploadé et l'écrire dans le fichier temporaire
            try {
                $sourcePath = $file->getRealPath();
                if (empty($sourcePath) || !file_exists($sourcePath)) {
                    $sourcePath = $file->getPathname();
                }
                
                if (!empty($sourcePath) && file_exists($sourcePath)) {
                    copy($sourcePath, $tempPath);
                } else {
                    $fileStream = fopen($file->getRealPath() ?: $file->getPathname(), 'r');
                    if ($fileStream !== false) {
                        stream_copy_to_stream($fileStream, $tempFile);
                        fclose($fileStream);
                    } else {
                        $fileContent = file_get_contents($file->getRealPath() ?: $file->getPathname());
                        if ($fileContent !== false) {
                            fwrite($tempFile, $fileContent);
                        } else {
                            fclose($tempFile);
                            return redirect()->route('admin.backups.index')
                                ->with('error', 'Impossible de lire le fichier uploadé. Veuillez réessayer.');
                        }
                    }
                }
            } catch (\Exception $e) {
                fclose($tempFile);
                \Log::error('Erreur lors de la création du fichier temporaire', [
                    'error' => $e->getMessage(),
                ]);
                return redirect()->route('admin.backups.index')
                    ->with('error', 'Erreur lors du traitement du fichier: ' . $e->getMessage());
            }
            
            \Log::info('Import de sauvegarde - Validation du zip', [
                'temp_path' => $tempPath,
                'file_exists' => file_exists($tempPath),
                'is_readable' => is_readable($tempPath),
                'file_size' => file_exists($tempPath) ? filesize($tempPath) : 0,
            ]);
            
            if (empty($tempPath) || !file_exists($tempPath) || !is_readable($tempPath)) {
                fclose($tempFile);
                return redirect()->route('admin.backups.index')
                    ->with('error', 'Impossible d\'accéder au fichier uploadé. Veuillez réessayer.');
            }
            
            if ($zip->open($tempPath) !== true) {
                fclose($tempFile);
                return redirect()->route('admin.backups.index')
                    ->with('error', 'Le fichier zip est corrompu ou invalide.');
            }
            
            // Vérifier que le zip contient au moins un fichier de base de données ou des fichiers
            $hasDbDump = false;
            $hasFiles = false;
            
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (substr($filename, -1) === '/') {
                    continue;
                }
                if (strpos($filename, 'db-dumps') !== false && pathinfo($filename, PATHINFO_EXTENSION) === 'sql') {
                    $hasDbDump = true;
                }
                if (strpos($filename, 'db-dumps') === false) {
                    $hasFiles = true;
                }
            }
            
            $zip->close();
            
            if (!$hasDbDump && !$hasFiles) {
                fclose($tempFile);
                return redirect()->route('admin.backups.index')
                    ->with('error', 'Le fichier zip ne contient pas de sauvegarde valide (aucun dump de base de données ou fichier trouvé).');
            }

            // Stocker le fichier dans le dossier de sauvegardes
            $destinationPath = Storage::disk($disk)->path($backupPath);
            $destinationDir = dirname($destinationPath);
            
            if (!is_dir($destinationDir)) {
                mkdir($destinationDir, 0755, true);
            }
            
            // Copier le fichier temporaire vers la destination finale
            try {
                $tempFileHandle = fopen($tempPath, 'r');
                if ($tempFileHandle === false) {
                    fclose($tempFile);
                    throw new \Exception('Impossible de rouvrir le fichier temporaire pour la copie.');
                }
                
                $destinationHandle = fopen($destinationPath, 'w');
                if ($destinationHandle === false) {
                    fclose($tempFileHandle);
                    fclose($tempFile);
                    throw new \Exception('Impossible de créer le fichier de destination.');
                }
                
                stream_copy_to_stream($tempFileHandle, $destinationHandle);
                
                fclose($tempFileHandle);
                fclose($destinationHandle);
                
                \Log::info('Fichier stocké avec succès', [
                    'source_path' => $tempPath,
                    'destination_path' => $destinationPath,
                    'file_exists' => file_exists($destinationPath),
                    'file_size' => file_exists($destinationPath) ? filesize($destinationPath) : 0,
                ]);
            } catch (\Exception $storageException) {
                \Log::error('Erreur lors du stockage du fichier', [
                    'error' => $storageException->getMessage(),
                    'temp_path' => $tempPath,
                    'destination_path' => $destinationPath,
                ]);
                
                fclose($tempFile);
                throw new \Exception('Erreur lors du stockage du fichier: ' . $storageException->getMessage());
            }
            
            fclose($tempFile);

            \Log::info('Sauvegarde importée avec succès', [
                'filename' => $originalFilename,
            ]);

            return redirect()->route('admin.backups.index')
                ->with('success', 'Sauvegarde importée avec succès. Le fichier a été ajouté à la liste des sauvegardes.');
                
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = $e->errors();
            $firstError = collect($errors)->flatten()->first();
            
            \Log::error('Erreur de validation lors de l\'import de la sauvegarde', [
                'errors' => $errors
            ]);
            
            return redirect()->route('admin.backups.index')
                ->with('error', $firstError ?: 'Erreur de validation lors de l\'import de la sauvegarde.');
        } catch (\Exception $e) {
            \Log::error('Erreur lors de l\'import de la sauvegarde', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            $errorMessage = 'Erreur lors de l\'import de la sauvegarde: ' . $e->getMessage();
            
            if (strlen($errorMessage) > 500) {
                $errorMessage = substr($errorMessage, 0, 500) . '...';
            }
            
            return redirect()->route('admin.backups.index')
                ->with('error', $errorMessage);
        }
    }
    // ====================================================================
    // ⚠️  FIN DE LA SECTION CRITIQUE - IMPORT DE SAUVEGARDE ⚠️
    // ====================================================================
    // 
    // ⚠️  ATTENTION : La méthode ci-dessus est essentielle ⚠️
    // Ne pas supprimer la section d'import ci-dessus
    // ====================================================================

    /**
     * ====================================================================
     * ⚠️  SECTION CRITIQUE - RESTAURATION COMPLÈTE ⚠️
     * ====================================================================
     * 
     * ⚠️  ATTENTION : NE PAS SUPPRIMER CETTE SECTION ⚠️
     * 
     * Cette méthode et toutes les méthodes privées ci-dessous sont essentielles
     * pour la fonctionnalité de restauration complète des sauvegardes.
     * 
     * Méthodes incluses dans cette section :
     * - restore() : Méthode principale de restauration
     * - findDatabaseDump() : Trouve le dump SQL dans le zip
     * - restoreDatabase() : Restaure la base de données
     * - restoreFiles() : Restaure les fichiers de l'application
     * - deleteDirectory() : Supprime les dossiers temporaires
     * 
     * ⚠️  NE PAS MODIFIER OU SUPPRIMER SANS CONNAISSANCE ⚠️
     * ====================================================================
     * 
     * Restaurer une sauvegarde
     */
    public function restore(Request $request, string $backupName, DatabaseRestoreService $restore)
    {
        $this->checkPermission(request(), 'backups', 'restore');

        $request->validate([
            'confirm' => 'required|accepted',
            'confirmation_phrase' => 'required|string',
            'target_database' => 'required|string',
            'restore_mode' => 'required|in:database',
        ], [
            'confirm.accepted' => 'Vous devez confirmer la restauration.',
            'confirmation_phrase.required' => 'La phrase de confirmation serveur est obligatoire.',
            'target_database.required' => 'La base cible explicite est obligatoire.',
            'restore_mode.in' => 'Seul le mode database (DB-only) est autorisé sur cette route.',
        ]);

        try {
            $report = $restore->restore(
                urldecode($backupName),
                (string) $request->input('target_database'),
                (string) $request->input('confirmation_phrase'),
                false,
            );

            return redirect()->route('admin.backups.index')
                ->with('success', 'Restauration DB-only vers '.$report['target'].' terminée. Aucun fichier applicatif n\'a été modifié.');
        } catch (ProtectedDatabaseException $e) {
            abort(403, $e->getMessage());
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'already in progress')) {
                abort(409, $e->getMessage());
            }

            return redirect()->route('admin.backups.index')
                ->with('error', $e->getMessage());
        }
    }

    public function restoreApplicationFiles(Request $request, string $backupName, ApplicationFilesRestoreService $files)
    {
        $this->checkPermission(request(), 'backups', 'restore_files');

        $request->validate([
            'confirmation_phrase' => 'required|string',
            'dry_run' => 'required|accepted',
        ]);

        try {
            $report = $files->restore(
                urldecode($backupName),
                (string) $request->input('confirmation_phrase'),
                true,
            );

            return redirect()->route('admin.backups.index')
                ->with('success', 'File restore dry-run only: '.json_encode($report));
        } catch (ProtectedDatabaseException|\RuntimeException $e) {
            abort(403, $e->getMessage());
        }
    }

    /**
     * Retired fused restore. Kept only to fail closed if called accidentally.
     */
    private function executeRestore(Request $request, string $backupName)
    {
        throw new \RuntimeException(
            'DATABASE SAFETY BLOCK: fused executeRestore(DB+files) has been removed. Use DatabaseRestoreService.',
        );
    }

    /**
     * Trouver le fichier de dump de base de données dans le dossier extrait
     */
    private function findDatabaseDump(string $tempDir): ?string
    {
        // Chercher dans db-dumps/
        $dbDumpsDir = $tempDir . DIRECTORY_SEPARATOR . 'db-dumps';
        if (is_dir($dbDumpsDir)) {
            $files = glob($dbDumpsDir . DIRECTORY_SEPARATOR . '*.sql');
            if (!empty($files)) {
                return $files[0];
            }
        }
        
        // Chercher récursivement dans tout le dossier
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'sql') {
                return $file->getPathname();
            }
        }
        
        return null;
    }

    /**
     * Retired — use DatabaseRestoreService with an explicit allow-listed target.
     */
    private function restoreDatabase(string $dumpPath): void
    {
        throw new \RuntimeException(
            'DATABASE SAFETY BLOCK: BackupController::restoreDatabase is retired. Use DatabaseRestoreService with an explicit target.',
        );
    }

    /**
     * Retired — file restore is never coupled to DB restore.
     */
    private function restoreFiles(string $tempDir, string $targetPath): void
    {
        throw new \RuntimeException(
            'DATABASE SAFETY BLOCK: BackupController::restoreFiles cannot run during DB restore. Use ApplicationFilesRestoreService.',
        );
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $pathItem = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($pathItem)) {
                $this->deleteDirectory($pathItem);
            } else {
                unlink($pathItem);
            }
        }
        rmdir($dir);
    }

    /**
     * Obtenir le nom du dossier de sauvegarde
     */
    private function getBackupFolderName(): string
    {
        // Utiliser le nom de l'application depuis la config backup (qui utilise APP_NAME)
        // Cela garantit que le contrôleur cherche dans le même dossier que le package Spatie Backup
        return config('backup.backup.name', 'laravel-backup');
    }

    /**
     * Obtenir la liste des sauvegardes
     */
    private function getBackups(): array
    {
        $disk = config('backup.backup.destination.disks')[0] ?? 'local';
        $backupName = $this->getBackupFolderName();
        
        // Utiliser directement le système de fichiers car l'API Spatie a des problèmes
        // de détection sur Windows
        $backups = $this->getBackupsFromFilesystem($disk, $backupName);
        
        \Log::info('Sauvegardes récupérées', [
            'count' => count($backups),
            'backup_name' => $backupName
        ]);
        
        return $backups;
    }
    
    /**
     * Obtenir les sauvegardes depuis le système de fichiers (fallback)
     */
    private function getBackupsFromFilesystem(string $disk, string $backupName): array
    {
        $diskRoot = Storage::disk($disk)->path('');
        $diskRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $diskRoot), DIRECTORY_SEPARATOR);
        $backupPathNormalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $backupName);
        $fullPath = $diskRoot . DIRECTORY_SEPARATOR . $backupPathNormalized;
        
        // Utiliser realpath si possible, sinon utiliser le chemin tel quel
        $normalizedPath = realpath($fullPath);
        if (!$normalizedPath) {
            // Si realpath échoue, utiliser le chemin tel quel si le dossier existe
            if (is_dir($fullPath)) {
                $normalizedPath = $fullPath;
            }
        }
        
        \Log::info('Recherche de sauvegardes', [
            'disk' => $disk,
            'backup_name' => $backupName,
            'disk_root' => $diskRoot,
            'full_path' => $fullPath,
            'normalized_path' => $normalizedPath,
            'exists' => $normalizedPath ? is_dir($normalizedPath) : false,
            'realpath_worked' => realpath($fullPath) !== false
        ]);
        
        if (!$normalizedPath || !is_dir($normalizedPath)) {
            \Log::warning('Le dossier de sauvegarde n\'existe pas', [
                'path' => $fullPath,
                'normalized' => $normalizedPath,
                'disk_root' => $diskRoot,
                'backup_name' => $backupName
            ]);
            return [];
        }
        
        $backups = [];
        $files = [];
        
        try {
            // Utiliser DirectoryIterator pour trouver tous les fichiers zip
            // glob() peut avoir des problèmes avec les chemins Windows
            $iterator = new \DirectoryIterator($normalizedPath);
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isDot()) {
                    continue;
                }
                
                if ($fileInfo->isFile()) {
                    $extension = strtolower($fileInfo->getExtension());
                    if ($extension === 'zip') {
                        $files[] = $fileInfo->getPathname();
                    }
                }
            }
            
            \Log::info('Fichiers zip trouvés', [
                'count' => count($files),
                'path' => $normalizedPath,
                'files' => array_map('basename', $files)
            ]);
        } catch (\Exception $e) {
            \Log::error('Erreur lors de la lecture du dossier', [
                'path' => $normalizedPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
        
        foreach ($files as $filePath) {
            try {
                $fileInfo = new \SplFileInfo($filePath);
                $relativePath = str_replace($diskRoot . DIRECTORY_SEPARATOR, '', $filePath);
                $relativePath = str_replace('\\', '/', $relativePath);
                
                $backups[] = [
                    'name' => $fileInfo->getFilename(),
                    'path' => $relativePath,
                    'size' => $this->formatBytes($fileInfo->getSize()),
                    'date' => date('Y-m-d H:i:s', $fileInfo->getMTime()),
                    'timestamp' => $fileInfo->getMTime(),
                ];
            } catch (\Exception $e) {
                \Log::warning('Impossible de lire le fichier', [
                    'file' => $filePath,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        usort($backups, function ($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        \Log::info('Sauvegardes depuis le système de fichiers', [
            'count' => count($backups),
            'path' => $normalizedPath
        ]);
        
        return $backups;
    }

    /**
     * Formater la taille en bytes
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
