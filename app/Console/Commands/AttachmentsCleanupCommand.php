<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AttachmentsCleanupCommand extends Command
{
    protected $signature = 'attachments:cleanup {--dry-run : Afficher les fichiers orphelins sans les supprimer}';

    protected $description = 'Identifie et supprime les fichiers orphelins du répertoire attachments';

    public function handle(): int
    {
        $diskName = config('attachments.disk', 'local');
        $disk = Storage::disk($diskName);
        $dryRun = (bool) $this->option('dry-run');

        if (!$disk->exists('attachments')) {
            $this->info('Aucun répertoire attachments trouvé.');
            return self::SUCCESS;
        }

        $files = $disk->allFiles('attachments');
        $dbPaths = Attachment::query()->pluck('path')->flip();
        $orphans = [];

        foreach ($files as $file) {
            if (!$dbPaths->has($file)) {
                $orphans[] = $file;
            }
        }

        if ($orphans === []) {
            $this->info('Aucun fichier orphelin détecté.');
            return self::SUCCESS;
        }

        $this->warn(sprintf('%d fichier(s) orphelin(s) détecté(s) :', count($orphans)));

        foreach ($orphans as $orphan) {
            $this->line('  - ' . $orphan);
        }

        if ($dryRun) {
            $this->info('Mode dry-run : aucune suppression effectuée.');
            return self::SUCCESS;
        }

        if (!$this->confirm('Supprimer ces fichiers orphelins ?')) {
            $this->info('Opération annulée.');
            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ($orphans as $orphan) {
            if ($disk->delete($orphan)) {
                $deleted++;
            }
        }

        $this->info("{$deleted} fichier(s) supprimé(s).");

        return self::SUCCESS;
    }
}
