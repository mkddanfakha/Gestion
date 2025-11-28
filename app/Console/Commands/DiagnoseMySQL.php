<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

class DiagnoseMySQL extends Command
{
    protected $signature = 'mysql:diagnose';
    protected $description = 'Diagnostic complet de la connexion MySQL';

    public function handle()
    {
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('DIAGNOSTIC COMPLET MySQL');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('');
        
        // 1. Configuration Laravel
        $this->info('1. Configuration Laravel:');
        $this->info('   Host: ' . Config::get('database.connections.mysql.host'));
        $this->info('   Port: ' . Config::get('database.connections.mysql.port'));
        $this->info('   Database: ' . Config::get('database.connections.mysql.database'));
        $this->info('   Username: ' . Config::get('database.connections.mysql.username'));
        $this->info('   Unix Socket: ' . (Config::get('database.connections.mysql.unix_socket') ?? 'non défini'));
        $this->info('');
        
        // 2. Test connexion PDO
        $this->info('2. Test connexion PDO:');
        try {
            $pdo = DB::connection('mysql')->getPdo();
            $this->info('   ✓ Connexion PDO réussie');
            
            // Vérifier les variables MySQL
            $result = $pdo->query("SHOW VARIABLES LIKE 'bind_address'");
            $bindAddress = $result->fetch();
            $this->info('   bind_address MySQL: ' . ($bindAddress ? $bindAddress['Value'] : 'non trouvé'));
            
            $result = $pdo->query("SHOW VARIABLES LIKE 'port'");
            $port = $result->fetch();
            $this->info('   Port MySQL: ' . ($port ? $port['Value'] : 'non trouvé'));
            
        } catch (\Exception $e) {
            $this->error('   ✗ Connexion PDO échouée: ' . $e->getMessage());
        }
        $this->info('');
        
        // 3. Test mysqldump direct
        $this->info('3. Test mysqldump:');
        $host = Config::get('database.connections.mysql.host');
        $port = Config::get('database.connections.mysql.port');
        $database = Config::get('database.connections.mysql.database');
        $username = Config::get('database.connections.mysql.username');
        $password = Config::get('database.connections.mysql.password');
        $dumpPath = Config::get('database.connections.mysql.dump.dump_binary_path');
        
        $mysqldump = $dumpPath . DIRECTORY_SEPARATOR . 'mysqldump.exe';
        
        if (!file_exists($mysqldump)) {
            $this->error('   ✗ mysqldump.exe introuvable à: ' . $mysqldump);
        } else {
            $this->info('   ✓ mysqldump trouvé: ' . $mysqldump);
            
            // Test avec --help
            $command = "\"{$mysqldump}\" --help 2>&1";
            exec($command, $output, $returnVar);
            if ($returnVar === 0) {
                $this->info('   ✓ mysqldump peut être exécuté');
            } else {
                $this->error('   ✗ mysqldump ne peut pas être exécuté');
            }
            
            // Test connexion avec différents hosts
            $hosts = ['127.0.0.1', 'localhost', '::1'];
            foreach ($hosts as $testHost) {
                $this->info('');
                $this->info("   Test avec host: {$testHost}");
                $testCommand = "\"{$mysqldump}\" --host={$testHost} --port={$port} --user={$username}";
                if (!empty($password)) {
                    $testCommand .= " --password={$password}";
                }
                $testCommand .= " --databases {$database} --no-data 2>&1";
                
                $testOutput = [];
                $testReturnVar = 0;
                exec($testCommand, $testOutput, $testReturnVar);
                
                if ($testReturnVar === 0) {
                    $this->info("   ✓ Connexion réussie avec {$testHost}");
                } else {
                    $errorMessage = implode("\n", $testOutput);
                    $this->error("   ✗ Connexion échouée avec {$testHost}");
                    $this->line("   Erreur: " . substr($errorMessage, 0, 200));
                }
            }
        }
        $this->info('');
        
        // 4. Vérifier le fichier my.ini
        $this->info('4. Vérification du fichier my.ini:');
        $possiblePaths = [
            'C:/wamp64/bin/mysql/mysql9.1.0/my.ini',
            'C:/wamp64/bin/mysql/mysql9.1.0/data/my.ini',
            'C:/wamp64/bin/mysql/mysql9.1.0/my.cnf',
        ];
        
        $myIniFound = false;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $this->info("   ✓ Fichier trouvé: {$path}");
                $myIniFound = true;
                
                // Lire le fichier et chercher bind-address
                $content = file_get_contents($path);
                if (preg_match('/bind-address\s*=\s*([^\s\n]+)/i', $content, $matches)) {
                    $this->info("   bind-address trouvé: " . trim($matches[1]));
                    if (strtolower(trim($matches[1])) === '0.0.0.0' || strtolower(trim($matches[1])) === '*') {
                        $this->info("   ✓ bind-address est correctement configuré");
                    } else {
                        $this->warn("   ⚠ bind-address n'est pas 0.0.0.0 ou *: " . trim($matches[1]));
                        $this->warn("   Il devrait être: bind-address = 0.0.0.0");
                    }
                } else {
                    $this->warn("   ⚠ bind-address non trouvé dans le fichier");
                    $this->warn("   Vous devez ajouter: bind-address = 0.0.0.0 dans la section [mysqld]");
                }
                
                // Vérifier la section [mysqld]
                if (preg_match('/\[mysqld\](.*?)(?=\[|\Z)/is', $content, $matches)) {
                    $this->info("   ✓ Section [mysqld] trouvée");
                } else {
                    $this->error("   ✗ Section [mysqld] non trouvée");
                }
                
                break;
            }
        }
        
        if (!$myIniFound) {
            $this->warn("   ⚠ Fichier my.ini non trouvé aux emplacements standards");
            $this->info("   Cherchez manuellement le fichier my.ini dans:");
            $this->info("   C:/wamp64/bin/mysql/mysql9.1.0/");
        }
        $this->info('');
        
        // 5. Vérifier les services Windows
        $this->info('5. Vérification des services Windows:');
        exec('sc query wampapache64', $apacheOutput, $apacheReturn);
        exec('sc query wampmysqld64', $mysqlOutput, $mysqlReturn);
        
        if ($mysqlReturn === 0) {
            $mysqlStatus = implode("\n", $mysqlOutput);
            if (strpos($mysqlStatus, 'RUNNING') !== false) {
                $this->info('   ✓ Service MySQL (wampmysqld64) est en cours d\'exécution');
            } else {
                $this->error('   ✗ Service MySQL (wampmysqld64) n\'est pas en cours d\'exécution');
            }
        } else {
            $this->warn('   ⚠ Impossible de vérifier le service MySQL');
        }
        $this->info('');
        
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('FIN DU DIAGNOSTIC');
        $this->info('═══════════════════════════════════════════════════════════');
        
        return 0;
    }
}

