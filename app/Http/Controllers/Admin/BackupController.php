<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CreateBackupJob;
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
        
        \Log::info('Permission vérifiée, début de la création');

        try {
            $onlyDb = $request->input('only_db', false);
            
            // Augmenter les limites d'exécution pour permettre une sauvegarde longue
            set_time_limit(600); // 10 minutes
            ini_set('max_execution_time', 600);
            ini_set('memory_limit', '512M');
            
            // Exécuter la commande de sauvegarde
            \Log::info('Début de la création de la sauvegarde', ['only_db' => $onlyDb]);
            
            $exitCode = 0;
            $output = '';
            
            try {
                // FORCER la config à ne pas avoir unix_socket pour éviter que DbDumperFactory ne l'utilise
                // On doit le faire AVANT que createFromConnection() ne soit appelé
                $mysqlConfig = config('database.connections.mysql', []);
                if (isset($mysqlConfig['unix_socket'])) {
                    unset($mysqlConfig['unix_socket']);
                    config(['database.connections.mysql' => $mysqlConfig]);
                }
                
                // Vérifier que la config a bien été modifiée
                $dbConfig = config('database.connections.mysql');
                \Log::info('Exécution de la commande backup:run', [
                    'only_db' => $onlyDb,
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => ini_get('max_execution_time'),
                    'db_host' => $dbConfig['host'],
                    'db_port' => $dbConfig['port'],
                    'db_socket' => $dbConfig['unix_socket'] ?? 'non défini (OK)',
                    'dump_path' => $dbConfig['dump']['dump_binary_path'] ?? 'non défini',
                    'php_sapi' => php_sapi_name(),
                    'user' => get_current_user(),
                    'config_modified' => !isset($dbConfig['unix_socket']),
                ]);
                
                // Sur Windows, dans le contexte web, exécuter la commande via un processus séparé
                // pour éviter les problèmes de permissions avec les sockets TCP/IP
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && php_sapi_name() !== 'cli') {
                    // Exécuter via un processus séparé avec cmd.exe pour avoir un environnement propre
                    $artisanPath = base_path('artisan');
                    $command = sprintf(
                        'cd /d "%s" && php "%s" backup:run%s',
                        base_path(),
                        $artisanPath,
                        $onlyDb ? ' --only-db' : ''
                    );
                    
                    \Log::info('Exécution via processus séparé', ['command' => $command]);
                    
                    $descriptorspec = [
                        0 => ['pipe', 'r'],  // stdin
                        1 => ['pipe', 'w'],  // stdout
                        2 => ['pipe', 'w'],  // stderr
                    ];
                    
                    $process = proc_open($command, $descriptorspec, $pipes, base_path(), null, [
                        'bypass_shell' => false,
                    ]);
                    
                    if (!is_resource($process)) {
                        throw new \Exception('Impossible de créer le processus de sauvegarde');
                    }
                    
                    // Fermer stdin
                    fclose($pipes[0]);
                    
                    // Lire stdout et stderr
                    $artisanOutput = stream_get_contents($pipes[1]);
                    $errorOutput = stream_get_contents($pipes[2]);
                    
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    
                    // Obtenir le code de sortie
                    $exitCode = proc_close($process);
                    
                    // Combiner les outputs
                    $artisanOutput = ($artisanOutput ?: '') . ($errorOutput ?: '');
                    $output = '';
                } else {
                    // Capturer l'output buffer pour voir ce qui se passe
                    ob_start();
                    
                    if ($onlyDb) {
                        $exitCode = Artisan::call('backup:run', ['--only-db' => true]);
                    } else {
                        $exitCode = Artisan::call('backup:run');
                    }
                    
                    $output = ob_get_clean();
                    $artisanOutput = Artisan::output();
                }
                
                \Log::info('Commande backup:run exécutée', [
                    'exit_code' => $exitCode,
                    'output_length' => strlen($artisanOutput),
                    'output_preview' => substr($artisanOutput, 0, 1000),
                    'ob_output' => substr($output, 0, 200),
                    'full_output' => $artisanOutput, // Log complet pour debug
                ]);
                
                // Vérifier si la sauvegarde a réussi malgré les erreurs de notification
                $backupSucceeded = strpos($artisanOutput, 'Backup completed!') !== false 
                    || strpos($artisanOutput, 'Successfully copied zip') !== false
                    || strpos($artisanOutput, 'Backup was successful') !== false;
                
                // Si la sauvegarde a réussi, ignorer les erreurs de notification
                if ($backupSucceeded) {
                    \Log::info('Sauvegarde réussie (erreurs de notification ignorées)', [
                        'exit_code' => $exitCode,
                        'output_preview' => substr($artisanOutput, 0, 300)
                    ]);
                    // Continuer même si exitCode !== 0 (peut être dû à l'échec de la notification)
                } elseif ($exitCode !== 0) {
                    // La sauvegarde a vraiment échoué
                    // Extraire le message d'erreur de l'output
                    $errorMessage = "La commande de sauvegarde a échoué";
                    $detailedError = "";
                    
                    if (strpos($artisanOutput, 'The dump process failed') !== false) {
                        // Extraire le message d'erreur de mysqldump
                        // Chercher "mysqldump: Got error:" ou "mysqldump: error:"
                        if (preg_match('/mysqldump:.*?Got error:\s*(\d+):\s*(.*?)(?:\n|$)/i', $artisanOutput, $matches)) {
                            $detailedError = trim($matches[2]);
                            $errorMessage = "Erreur MySQL: " . $detailedError;
                        } elseif (preg_match('/mysqldump:.*?error:\s*(\d+):\s*(.*?)(?:\n|$)/i', $artisanOutput, $matches)) {
                            $detailedError = trim($matches[2]);
                            $errorMessage = "Erreur MySQL: " . $detailedError;
                        } elseif (preg_match('/Error Output\s*=\s*=\s*=\s*=\s*=\s*=\s*\n(.*?)(?:\n\n|Backup failed|$)/s', $artisanOutput, $matches)) {
                            $detailedError = trim($matches[1]);
                            // Nettoyer le message (enlever les lignes vides et les "======")
                            $detailedError = preg_replace('/^=+\s*$/m', '', $detailedError);
                            $detailedError = preg_replace('/\n\s*\n/', "\n", $detailedError);
                            $detailedError = trim($detailedError);
                            
                            if (!empty($detailedError) && strlen($detailedError) > 5) {
                                $errorMessage = "Erreur MySQL: " . $detailedError;
                            } else {
                                $errorMessage = "Erreur lors de la sauvegarde de la base de données. Vérifiez la configuration MySQL.";
                            }
                        } else {
                            $errorMessage = "Erreur lors de la sauvegarde de la base de données. Vérifiez la configuration MySQL et que le service MySQL est démarré.";
                        }
                        
                        // Ajouter des suggestions selon le type d'erreur
                        if (strpos($artisanOutput, "Can't create TCP/IP socket") !== false || strpos($detailedError, "Can't create TCP/IP socket") !== false) {
                            $errorMessage .= "\n\nSolutions possibles:\n";
                            $errorMessage .= "1. Dans WAMP, allez dans MySQL > my.ini\n";
                            $errorMessage .= "   - Cherchez la section [mysqld] (généralement vers la ligne 50-60)\n";
                            $errorMessage .= "   - Ajoutez la ligne: bind-address = 0.0.0.0\n";
                            $errorMessage .= "   - Sauvegardez le fichier\n";
                            $errorMessage .= "2. Redémarrez MySQL dans WAMP (clic droit sur WAMP > MySQL > Redémarrer)\n";
                            $errorMessage .= "3. Vérifiez que le service MySQL Windows est démarré (Services Windows)\n";
                            $errorMessage .= "4. Si le problème persiste, essayez d'exécuter 'mysqldump' manuellement depuis la ligne de commande pour tester la connexion";
                        } elseif (strpos($artisanOutput, "Unknown MySQL server host") !== false || strpos($detailedError, "Unknown MySQL server host") !== false) {
                            $errorMessage .= "\n\nSolutions possibles:\n";
                            $errorMessage .= "1. Dans votre fichier .env, changez DB_HOST de 'localhost' à '127.0.0.1'\n";
                            $errorMessage .= "2. Exécutez 'php artisan config:clear' après la modification\n";
                            $errorMessage .= "3. Vérifiez que MySQL est démarré dans WAMP";
                        } elseif (strpos($artisanOutput, "Access denied") !== false || strpos($detailedError, "Access denied") !== false) {
                            $errorMessage .= "\n\nSuggestion: Vérifiez les identifiants MySQL dans votre fichier .env (DB_USERNAME, DB_PASSWORD).";
                        } elseif (strpos($artisanOutput, "Unknown database") !== false || strpos($detailedError, "Unknown database") !== false) {
                            $errorMessage .= "\n\nSuggestion: Vérifiez le nom de la base de données dans votre fichier .env (DB_DATABASE).";
                        }
                    }
                    
                    \Log::error('La commande backup:run a échoué', [
                        'exit_code' => $exitCode,
                        'output' => $artisanOutput,
                        'ob_output' => $output,
                        'error_message' => $errorMessage,
                        'detailed_error' => $detailedError
                    ]);
                    throw new \Exception($errorMessage);
                }
                
            } catch (\Exception $artisanException) {
                // Vérifier si c'est une erreur de notification ou une vraie erreur de sauvegarde
                $isNotificationError = strpos($artisanException->getMessage(), 'notification') !== false
                    || strpos($artisanException->getMessage(), 'SMTP') !== false
                    || strpos($artisanException->getMessage(), 'BackupHasFailed') !== false;
                
                // Si c'est juste une erreur de notification, vérifier si la sauvegarde a réussi
                if ($isNotificationError) {
                    // Vérifier si un fichier de sauvegarde a été créé récemment
                    $disk = config('backup.backup.destination.disks')[0] ?? 'local';
                    $backupName = $this->getBackupFolderName();
                    $diskRoot = Storage::disk($disk)->path('');
                    $backupPath = $diskRoot . DIRECTORY_SEPARATOR . $backupName;
                    
                    if (is_dir($backupPath)) {
                        $backups = $this->getBackups();
                        // Si une sauvegarde a été créée dans les 30 dernières secondes, considérer que c'est un succès
                        $recentBackup = collect($backups)->first(function ($backup) {
                            return (time() - strtotime($backup['date'])) < 30;
                        });
                        
                        if ($recentBackup) {
                            \Log::info('Sauvegarde réussie malgré l\'erreur de notification', [
                                'backup' => $recentBackup['name'],
                                'notification_error' => $artisanException->getMessage()
                            ]);
                            // Ne pas propager l'exception, la sauvegarde a réussi
                            // Continuer avec le reste du code pour rediriger vers la page de succès
                            goto backup_success;
                            goto backup_success;
                        }
                    }
                }
                
                \Log::error('Erreur lors de l\'exécution de la commande backup:run', [
                    'message' => $artisanException->getMessage(),
                    'file' => $artisanException->getFile(),
                    'line' => $artisanException->getLine(),
                    'trace' => $artisanException->getTraceAsString(),
                    'is_notification_error' => $isNotificationError
                ]);
                
                // Propager l'exception pour qu'elle soit capturée par le catch externe
                throw $artisanException;
            }
            
            backup_success:
            \Log::info('Sauvegarde créée avec succès');
            
            // Attendre un peu pour que le fichier soit complètement écrit et visible
            sleep(2);
            
            // Vérifier que le fichier existe bien
            $disk = config('backup.backup.destination.disks')[0] ?? 'local';
            $backupName = $this->getBackupFolderName();
            $diskRoot = Storage::disk($disk)->path('');
            $backupPath = $diskRoot . DIRECTORY_SEPARATOR . $backupName;
            
            \Log::info('Vérification du dossier de sauvegarde après création', [
                'path' => $backupPath,
                'exists' => is_dir($backupPath)
            ]);
            
            return redirect()->route('admin.backups.index')
                ->with('success', 'Sauvegarde créée avec succès.');
        } catch (\Exception $e) {
            \Log::error('Erreur lors de la création de la sauvegarde', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
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
    public function restore(Request $request, string $backupName)
    {
        $this->checkPermission(request(), 'backups', 'restore');

        $request->validate([
            'confirm' => 'required|accepted',
        ], [
            'confirm.accepted' => 'Vous devez confirmer la restauration. Cette action est irréversible.',
        ]);

        try {
            // Augmenter les limites d'exécution pour permettre une restauration longue
            set_time_limit(600); // 10 minutes
            ini_set('max_execution_time', 600);
            ini_set('memory_limit', '512M');
            
            $disk = config('backup.backup.destination.disks')[0] ?? 'local';
            $backupName = urldecode($backupName);
            $backupPath = $this->getBackupFolderName() . '/' . $backupName;

            if (!Storage::disk($disk)->exists($backupPath)) {
                return redirect()->route('admin.backups.index')
                    ->with('error', 'Sauvegarde introuvable.');
            }

            \Log::info('Début de la restauration', ['backup' => $backupName]);

            // Chemin complet du fichier zip
            $zipPath = Storage::disk($disk)->path($backupPath);
            
            // Créer un dossier temporaire pour l'extraction
            $tempDir = storage_path('app/restore-temp/' . uniqid('restore_', true));
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            try {
                // 1. Extraire le zip
                \Log::info('Extraction du zip', ['zip_path' => $zipPath, 'temp_dir' => $tempDir]);
                
                $zip = new \ZipArchive();
                if ($zip->open($zipPath) !== true) {
                    throw new \Exception('Impossible d\'ouvrir le fichier zip de sauvegarde.');
                }
                
                $zip->extractTo($tempDir);
                $zip->close();
                
                \Log::info('Zip extrait avec succès', ['temp_dir' => $tempDir]);

                // 2. Restaurer la base de données
                $dbDumpPath = $this->findDatabaseDump($tempDir);
                
                if ($dbDumpPath) {
                    \Log::info('Restauration de la base de données', ['dump_path' => $dbDumpPath]);
                    $this->restoreDatabase($dbDumpPath);
                    \Log::info('Base de données restaurée avec succès');
                } else {
                    \Log::warning('Aucun dump de base de données trouvé dans la sauvegarde');
                }

                // 3. Restaurer les fichiers (sauf certains dossiers sensibles)
                \Log::info('Restauration des fichiers');
                $this->restoreFiles($tempDir, base_path());
                \Log::info('Fichiers restaurés avec succès');
                
                // 4. Nettoyer les caches après restauration
                \Log::info('Nettoyage des caches');
                Artisan::call('config:clear');
                Artisan::call('cache:clear');
                Artisan::call('view:clear');
                Artisan::call('route:clear');
                \Log::info('Caches nettoyés');

                // 5. Nettoyer le dossier temporaire
                $this->deleteDirectory($tempDir);

                \Log::info('Restauration terminée avec succès');
                
                return redirect()->route('admin.backups.index')
                    ->with('success', 'Sauvegarde restaurée avec succès. L\'application a été restaurée à l\'état de la sauvegarde.');
                    
            } catch (\Exception $e) {
                // Nettoyer le dossier temporaire en cas d'erreur
                if (is_dir($tempDir)) {
                    $this->deleteDirectory($tempDir);
                }
                throw $e;
            }
            
        } catch (\Exception $e) {
            \Log::error('Erreur lors de la restauration', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()->route('admin.backups.index')
                ->with('error', 'Erreur lors de la restauration: ' . $e->getMessage());
        }
    }
    
    /**
     * ====================================================================
     * ⚠️  MÉTHODE CRITIQUE - PARTIE DE LA RESTAURATION ⚠️
     * ====================================================================
     * 
     * ⚠️  NE PAS SUPPRIMER - ESSENTIELLE POUR LA RESTAURATION ⚠️
     * 
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
     * ====================================================================
     * ⚠️  MÉTHODE CRITIQUE - PARTIE DE LA RESTAURATION ⚠️
     * ====================================================================
     * 
     * ⚠️  NE PAS SUPPRIMER - ESSENTIELLE POUR LA RESTAURATION ⚠️
     * 
     * Restaurer la base de données depuis un dump SQL
     */
    private function restoreDatabase(string $dumpPath): void
    {
        $dbConfig = config('database.connections.mysql');
        $dbName = $dbConfig['database'];
        $dbUser = $dbConfig['username'];
        $dbPassword = $dbConfig['password'] ?? '';
        $dbHost = $dbConfig['host'] ?? '127.0.0.1';
        $dbPort = $dbConfig['port'] ?? 3306;
        
        // Utiliser PDO pour restaurer la base de données
        try {
            $pdo = new \PDO(
                "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
                $dbUser,
                $dbPassword,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 300, // 5 minutes
                ]
            );
            
            \Log::info('Connexion PDO établie, début de la restauration de la base de données');
            
            // Supprimer et recréer la base de données
            $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
            $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");
            
            \Log::info('Base de données recréée', ['database' => $dbName]);
            
            // Lire le dump SQL par morceaux pour éviter les problèmes de mémoire avec les gros fichiers
            $dumpSize = filesize($dumpPath);
            \Log::info('Taille du dump SQL', ['size' => $dumpSize, 'size_mb' => round($dumpSize / 1024 / 1024, 2)]);
            
            // Pour les gros fichiers, utiliser une approche différente
            if ($dumpSize > 50 * 1024 * 1024) { // Plus de 50 MB
                // Utiliser mysql.exe pour les gros fichiers
                $mysqlPath = config('database.connections.mysql.dump.dump_binary_path', 'C:/wamp64/bin/mysql/mysql9.1.0/bin');
                if (is_dir($mysqlPath)) {
                    $mysqlExe = $mysqlPath . DIRECTORY_SEPARATOR . 'mysql.exe';
                } else {
                    $mysqlExe = 'mysql';
                }
                
                // Sur Windows, dans le contexte web, utiliser un processus séparé
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && php_sapi_name() !== 'cli') {
                    $command = sprintf(
                        'cd /d "%s" && type "%s" | "%s" --host=%s --port=%d --user=%s --password=%s --protocol=TCP %s',
                        base_path(),
                        escapeshellarg($dumpPath),
                        escapeshellarg($mysqlExe),
                        escapeshellarg($dbHost),
                        $dbPort,
                        escapeshellarg($dbUser),
                        escapeshellarg($dbPassword),
                        escapeshellarg($dbName)
                    );
                    
                    \Log::info('Exécution de la restauration de la base de données via mysql.exe (fichier volumineux)');
                    
                    $descriptorspec = [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ];
                    
                    $process = proc_open($command, $descriptorspec, $pipes, base_path(), null, [
                        'bypass_shell' => false,
                    ]);
                    
                    if (!is_resource($process)) {
                        throw new \Exception('Impossible de créer le processus de restauration de la base de données.');
                    }
                    
                    fclose($pipes[0]);
                    
                    $output = stream_get_contents($pipes[1]);
                    $errorOutput = stream_get_contents($pipes[2]);
                    
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    
                    $exitCode = proc_close($process);
                    
                    if ($exitCode !== 0) {
                        throw new \Exception('Erreur lors de la restauration de la base de données: ' . ($errorOutput ?: $output ?: 'Code de sortie: ' . $exitCode));
                    }
                    
                    \Log::info('Base de données restaurée via mysql.exe');
                    return;
                }
            }
            
            // Pour les fichiers plus petits, utiliser PDO
            $sql = file_get_contents($dumpPath);
            
            // Exécuter le dump SQL par morceaux pour éviter les problèmes de mémoire
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                function($stmt) {
                    return !empty($stmt) && !preg_match('/^--/', $stmt) && !preg_match('/^\/\*/', $stmt);
                }
            );
            
            $totalStatements = count($statements);
            \Log::info('Exécution des commandes SQL', ['total' => $totalStatements]);
            
            $executed = 0;
            foreach ($statements as $statement) {
                if (empty(trim($statement))) {
                    continue;
                }
                
                try {
                    $pdo->exec($statement);
                    $executed++;
                    
                    // Logger la progression tous les 100 statements
                    if ($executed % 100 === 0) {
                        \Log::info('Progression de la restauration', [
                            'executed' => $executed,
                            'total' => $totalStatements,
                            'percentage' => round(($executed / $totalStatements) * 100, 2)
                        ]);
                    }
                } catch (\PDOException $e) {
                    // Ignorer certaines erreurs non critiques
                    if (strpos($e->getMessage(), 'already exists') === false && 
                        strpos($e->getMessage(), 'Duplicate entry') === false) {
                        \Log::warning('Erreur lors de l\'exécution d\'une commande SQL', [
                            'error' => $e->getMessage(),
                            'statement_preview' => substr($statement, 0, 100)
                        ]);
                    }
                }
            }
            
            \Log::info('Base de données restaurée', [
                'executed' => $executed,
                'total' => $totalStatements
            ]);
            
        } catch (\PDOException $e) {
            throw new \Exception('Erreur lors de la restauration de la base de données: ' . $e->getMessage());
        }
    }
    
    /**
     * ====================================================================
     * ⚠️  MÉTHODE CRITIQUE - PARTIE DE LA RESTAURATION ⚠️
     * ====================================================================
     * 
     * ⚠️  NE PAS SUPPRIMER - ESSENTIELLE POUR LA RESTAURATION ⚠️
     * 
     * Restaurer les fichiers depuis le dossier extrait
     */
    private function restoreFiles(string $tempDir, string $targetPath): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        $basePath = base_path();
        $basePathNormalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $basePath);
        $projectName = basename($basePath);
        
        // Compter d'abord le nombre total de fichiers à restaurer
        $totalFiles = 0;
        foreach ($iterator as $item) {
            $relativeSourcePath = str_replace($tempDir . DIRECTORY_SEPARATOR, '', $item->getPathname());
            if (strpos($relativeSourcePath, 'db-dumps') === 0 || 
                strpos($relativeSourcePath, 'db-dumps' . DIRECTORY_SEPARATOR) === 0 ||
                $item->getExtension() === 'sql') {
                continue;
            }
            if (!$item->isDir()) {
                $totalFiles++;
            }
        }
        
        // Réinitialiser l'itérateur
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        $filesRestored = 0;
        $dirsCreated = 0;
        
        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            $relativeSourcePath = str_replace($tempDir . DIRECTORY_SEPARATOR, '', $sourcePath);
            
            // Ignorer le dossier db-dumps et les fichiers .sql
            if (strpos($relativeSourcePath, 'db-dumps') === 0 || 
                strpos($relativeSourcePath, 'db-dumps' . DIRECTORY_SEPARATOR) === 0 ||
                $item->getExtension() === 'sql') {
                continue;
            }
            
            // Ignorer certains dossiers/fichiers sensibles qui ne doivent pas être restaurés
            $excludedPaths = [
                'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'backup-temp',
                'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'restore-temp',
                'storage' . DIRECTORY_SEPARATOR . 'logs',
                '.env', // Ne pas restaurer .env pour éviter d'écraser la configuration actuelle
            ];
            
            foreach ($excludedPaths as $excludedPath) {
                if (strpos($relativeSourcePath, $excludedPath) === 0 || 
                    strpos($relativeSourcePath, str_replace(DIRECTORY_SEPARATOR, '/', $excludedPath)) === 0) {
                    continue 2; // Continuer la boucle externe
                }
            }
            
            // Normaliser les séparateurs de chemin
            $relativeSourcePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeSourcePath);
            
            // Si le chemin contient un chemin absolu Windows, l'extraire
            if (preg_match('/^[A-Z]:[\\\\\/].*?[\\\\\/]' . preg_quote($projectName, '/') . '[\\\\\/](.+)$/i', $relativeSourcePath, $matches)) {
                $relativeSourcePath = $matches[1];
            }
            elseif (preg_match('/^[A-Z]:[\\\\\/].*?[\\\\\/]' . preg_quote($projectName, '/') . '[\\\\\/](.+)$/i', $relativeSourcePath, $matches)) {
                $relativeSourcePath = $matches[1];
            }
            elseif (strpos($relativeSourcePath, $projectName . DIRECTORY_SEPARATOR) === 0) {
                $relativeSourcePath = substr($relativeSourcePath, strlen($projectName) + 1);
            }
            elseif (strpos($relativeSourcePath, $basePathNormalized . DIRECTORY_SEPARATOR) === 0) {
                $relativeSourcePath = substr($relativeSourcePath, strlen($basePathNormalized) + 1);
            }
            
            // Ignorer les fichiers vides ou les chemins invalides
            if (empty($relativeSourcePath) || $relativeSourcePath === '.' || $relativeSourcePath === '..') {
                continue;
            }
            
            $targetFilePath = $targetPath . DIRECTORY_SEPARATOR . $relativeSourcePath;
            
            // Sécurité : s'assurer que le chemin de destination est dans le répertoire cible
            $targetPathReal = realpath($targetPath) ?: $targetPath;
            $targetFilePath = $targetPathReal . DIRECTORY_SEPARATOR . $relativeSourcePath;
            $targetFilePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $targetFilePath);
            
            // Sécurité supplémentaire : vérifier que le chemin de destination est bien dans le répertoire cible
            $targetPathNormalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $targetPathReal);
            $targetFilePathNormalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $targetFilePath);
            
            if (strpos($targetFilePathNormalized, $targetPathNormalized) !== 0) {
                \Log::warning('Chemin de destination invalide, ignoré', [
                    'target_path' => $targetPathNormalized,
                    'file_path' => $targetFilePathNormalized
                ]);
                continue;
            }
            
            if ($item->isDir()) {
                // Créer le dossier s'il n'existe pas
                if (!is_dir($targetFilePath)) {
                    mkdir($targetFilePath, 0755, true);
                    $dirsCreated++;
                }
            } else {
                // Créer le dossier parent s'il n'existe pas
                $targetDir = dirname($targetFilePath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                
                // Copier le fichier
                if (copy($sourcePath, $targetFilePath)) {
                    $filesRestored++;
                    
                } else {
                    \Log::warning('Impossible de copier le fichier', [
                        'source' => $sourcePath,
                        'target' => $targetFilePath
                    ]);
                }
            }
        }
        
        \Log::info('Fichiers restaurés', [
            'files' => $filesRestored,
            'directories' => $dirsCreated
        ]);
    }
    
    /**
     * ====================================================================
     * ⚠️  MÉTHODE CRITIQUE - PARTIE DE LA RESTAURATION ⚠️
     * ====================================================================
     * 
     * ⚠️  NE PAS SUPPRIMER - ESSENTIELLE POUR LA RESTAURATION ⚠️
     * 
     * Supprimer un dossier récursivement
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
    // ====================================================================
    // ⚠️  FIN DE LA SECTION CRITIQUE - RESTAURATION COMPLÈTE ⚠️
    // ====================================================================
    // 
    // ⚠️  ATTENTION : Les méthodes ci-dessus sont essentielles ⚠️
    // Ne pas supprimer la section de restauration ci-dessus
    // ====================================================================

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
