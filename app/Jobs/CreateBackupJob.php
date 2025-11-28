<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;

class CreateBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Indique si seule la base de données doit être sauvegardée
     */
    public bool $onlyDb;
    
    /**
     * ID de l'utilisateur qui a lancé la sauvegarde
     */
    public int $userId;

    /**
     * Créer une nouvelle instance du job
     */
    public function __construct(bool $onlyDb = false, int $userId = null)
    {
        $this->onlyDb = $onlyDb;
        $this->userId = $userId ?? auth()->id() ?? 0;
    }

    /**
     * Exécuter le job
     */
    public function handle(): void
    {
        $progressKey = "backup_progress_{$this->userId}";
        
        try {
            // Mettre à jour la progression
            $this->updateProgress(10, $this->onlyDb ? 'Sauvegarde de la base de données en cours...' : 'Sauvegarde complète en cours...');
            
            if ($this->onlyDb) {
                Artisan::call('backup:run', ['--only-db' => true]);
            } else {
                Artisan::call('backup:run');
            }
            
            // Mettre à jour la progression à 95%
            $this->updateProgress(95, 'Sauvegarde réussie, finalisation...');
            
        } catch (\Exception $e) {
            $this->updateProgress(0, 'Erreur : ' . $e->getMessage(), 'error');
            \Log::error('Erreur lors de la création de la sauvegarde: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Mettre à jour la progression
     */
    private function updateProgress(int $percentage, string $message, string $status = 'running'): void
    {
        $progressKey = "backup_progress_{$this->userId}";
        
        $progress = [
            'percentage' => $percentage,
            'message' => $message,
            'status' => $status,
            'timestamp' => now()->timestamp
        ];
        
        Cache::put($progressKey, $progress, 600);
    }
}
