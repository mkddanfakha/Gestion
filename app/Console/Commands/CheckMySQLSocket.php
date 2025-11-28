<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckMySQLSocket extends Command
{
    protected $signature = 'mysql:check-socket';
    protected $description = 'Vérifie le socket/pipe MySQL disponible';

    public function handle()
    {
        try {
            $pdo = DB::connection('mysql')->getPdo();
            
            // Vérifier le socket MySQL
            $result = $pdo->query("SHOW VARIABLES LIKE 'socket'");
            $row = $result->fetch();
            
            if ($row) {
                $this->info('Socket MySQL: ' . $row['Value']);
                
                // Vérifier si c'est un pipe nommé Windows
                if (strpos($row['Value'], 'pipe') !== false || strpos($row['Value'], '\\\\.\\') !== false) {
                    $this->info('✓ Pipe nommé Windows détecté');
                    $this->info('Vous pouvez utiliser ce pipe au lieu de TCP/IP');
                    return 0;
                } else {
                    $this->info('Socket Unix détecté (non disponible sur Windows)');
                }
            } else {
                $this->warn('Socket MySQL non trouvé');
            }
            
            // Vérifier bind-address
            $result = $pdo->query("SHOW VARIABLES LIKE 'bind_address'");
            $row = $result->fetch();
            if ($row) {
                $this->info('bind_address: ' . $row['Value']);
            }
            
        } catch (\Exception $e) {
            $this->error('Erreur: ' . $e->getMessage());
            return 1;
        }
        
        return 0;
    }
}

