<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

class TestMySQLConnection extends Command
{
    protected $signature = 'mysql:test-connection';
    protected $description = 'Teste la connexion MySQL et mysqldump';

    public function handle()
    {
        $this->info('Test de la connexion MySQL...');
        
        try {
            DB::connection('mysql')->getPdo();
            $this->info('✓ Connexion PDO réussie');
        } catch (\Exception $e) {
            $this->error('✗ Connexion PDO échouée: ' . $e->getMessage());
            return 1;
        }
        
        $this->info('');
        $this->info('Test de mysqldump...');
        
        $host = Config::get('database.connections.mysql.host');
        $port = Config::get('database.connections.mysql.port');
        $database = Config::get('database.connections.mysql.database');
        $username = Config::get('database.connections.mysql.username');
        $password = Config::get('database.connections.mysql.password');
        $dumpPath = Config::get('database.connections.mysql.dump.dump_binary_path');
        
        $mysqldump = $dumpPath . DIRECTORY_SEPARATOR . 'mysqldump.exe';
        
        if (!file_exists($mysqldump)) {
            $this->error("✗ mysqldump.exe introuvable à: {$mysqldump}");
            return 1;
        }
        
        $this->info("Chemin mysqldump: {$mysqldump}");
        $this->info("Host: {$host}");
        $this->info("Port: {$port}");
        $this->info("Database: {$database}");
        $this->info("Username: {$username}");
        
        // Tester mysqldump avec --help d'abord
        $command = "\"{$mysqldump}\" --help 2>&1";
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);
        
        if ($returnVar !== 0) {
            $this->error('✗ mysqldump ne peut pas être exécuté');
            $this->error(implode("\n", $output));
            return 1;
        }
        
        $this->info('✓ mysqldump peut être exécuté');
        
        // Tester la connexion avec mysqldump
        $testCommand = "\"{$mysqldump}\" --host={$host} --port={$port} --user={$username}";
        if (!empty($password)) {
            $testCommand .= " --password={$password}";
        }
        $testCommand .= " --databases {$database} --no-data 2>&1";
        
        $this->info('');
        $this->info('Test de connexion mysqldump...');
        $this->info("Commande: {$testCommand}");
        
        $testOutput = [];
        $testReturnVar = 0;
        exec($testCommand, $testOutput, $testReturnVar);
        
        if ($testReturnVar !== 0) {
            $this->error('✗ Connexion mysqldump échouée');
            $this->error(implode("\n", $testOutput));
            
            $errorMessage = implode("\n", $testOutput);
            
            if (strpos($errorMessage, "Can't create TCP/IP socket") !== false) {
                $this->info('');
                $this->warn('═══════════════════════════════════════════════════════════');
                $this->warn('SOLUTION: MySQL n\'accepte pas les connexions TCP/IP');
                $this->warn('═══════════════════════════════════════════════════════════');
                $this->info('');
                $this->info('Étapes détaillées à suivre:');
                $this->info('');
                $this->info('1. Ouvrez WAMP');
                $this->info('   → Cliquez sur l\'icône WAMP dans la barre des tâches');
                $this->info('');
                $this->info('2. Ouvrez le fichier my.ini');
                $this->info('   → Cliquez sur MySQL > my.ini');
                $this->info('   → Le fichier s\'ouvrira dans votre éditeur par défaut');
                $this->info('');
                $this->info('3. Trouvez la section [mysqld]');
                $this->info('   → Cherchez [mysqld] dans le fichier (généralement vers la ligne 50-60)');
                $this->info('');
                $this->info('4. Ajoutez ou modifiez bind-address');
                $this->info('   → Si la ligne "bind-address" existe, modifiez-la en:');
                $this->info('     bind-address = 0.0.0.0');
                $this->info('   → Si elle n\'existe pas, ajoutez-la après "port=3306":');
                $this->info('     [mysqld]');
                $this->info('     port=3306');
                $this->info('     bind-address = 0.0.0.0');
                $this->info('');
                $this->info('5. Sauvegardez le fichier');
                $this->info('   → Ctrl+S ou Fichier > Enregistrer');
                $this->info('');
                $this->info('6. Redémarrez MySQL');
                $this->info('   → Cliquez sur l\'icône WAMP > MySQL > Redémarrer le service');
                $this->info('   → OU: Clic droit sur WAMP > Redémarrer tous les services');
                $this->info('   → Attendez que l\'icône WAMP redevienne VERTE');
                $this->info('');
                $this->info('7. Retestez la connexion');
                $this->info('   → Exécutez: php artisan mysql:test-connection');
                $this->info('');
                $this->warn('═══════════════════════════════════════════════════════════');
                $this->info('');
                $this->info('Un guide détaillé est disponible dans: FIX_MYSQL_TCPIP.md');
            }
            
            return 1;
        }
        
        $this->info('✓ Connexion mysqldump réussie');
        $this->info('');
        $this->info('Tous les tests sont passés avec succès!');
        
        return 0;
    }
}

