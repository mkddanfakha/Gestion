<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Spatie\Backup\Tasks\Backup\DbDumperFactory;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\DbDumper;
use App\DbDumpers\MySqlForcedTcp;

class BackupServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Intercepter la création du DbDumper pour MySQL AVANT que createFromConnection() ne soit appelé
        // En utilisant extend(), on remplace la création initiale du dumper
        DbDumperFactory::extend('mysql', function () {
            $dbConfig = config('database.connections.mysql');
            
            // Retirer unix_socket de la config pour éviter que createFromConnection() ne l'utilise
            if (isset($dbConfig['unix_socket'])) {
                unset($dbConfig['unix_socket']);
            }
            
            // Utiliser notre classe personnalisée qui force TCP/IP
            $dbDumper = new MySqlForcedTcp();
            // Sur Windows, dans le contexte web, utiliser 'localhost' au lieu de '127.0.0.1'
            // car 'localhost' peut utiliser un mécanisme de connexion différent
            $host = $dbConfig['host'] ?? '127.0.0.1';
            if (php_sapi_name() !== 'cli' && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && $host === '127.0.0.1') {
                $host = 'localhost';
            }
            $dbDumper->setHost($host);
            $dbDumper->setPort((int)($dbConfig['port'] ?? 3306));
            $dbDumper->setDbName($dbConfig['database']);
            $dbDumper->setUserName($dbConfig['username']);
            $dbDumper->setPassword($dbConfig['password'] ?? '');
            
            // FORCER le socket à être vide pour utiliser TCP/IP
            // Cela doit être fait AVANT que DbDumperFactory::createFromConnection()
            // n'appelle setSocket() avec la valeur de la config
            $dbDumper->setSocket('');
            
            // Configuration supplémentaire
            if (isset($dbConfig['charset'])) {
                $dbDumper->setDefaultCharacterSet($dbConfig['charset']);
            }
            
            // Configuration du dump
            if (isset($dbConfig['dump'])) {
                if (isset($dbConfig['dump']['dump_binary_path'])) {
                    $dbDumper->setDumpBinaryPath($dbConfig['dump']['dump_binary_path']);
                }
                if (isset($dbConfig['dump']['use_single_transaction'])) {
                    if ($dbConfig['dump']['use_single_transaction']) {
                        $dbDumper->useSingleTransaction();
                    }
                }
                if (isset($dbConfig['dump']['timeout'])) {
                    $dbDumper->setTimeout($dbConfig['dump']['timeout']);
                }
            }
            
            return $dbDumper;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // S'assurer que unix_socket n'est pas défini dans la config
        // pour éviter que DbDumperFactory::createFromConnection() ne l'utilise
        $this->app->booted(function () {
            $mysqlConfig = config('database.connections.mysql', []);
            // Si unix_socket est défini à une chaîne vide, le retirer complètement
            // pour éviter que DbDumperFactory ne l'utilise
            if (isset($mysqlConfig['unix_socket']) && $mysqlConfig['unix_socket'] === '') {
                unset($mysqlConfig['unix_socket']);
                config(['database.connections.mysql' => $mysqlConfig]);
            }
        });
    }
}
