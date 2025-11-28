<?php

namespace App\DbDumpers;

use Spatie\DbDumper\Databases\MySql;
use Symfony\Component\Process\Process;

/**
 * Classe MySQL personnalisée qui force l'utilisation de TCP/IP
 * en s'assurant que le socket est toujours vide
 */
class MySqlForcedTcp extends MySql
{
    /**
     * Override setSocket pour toujours forcer une chaîne vide
     * Cela garantit que TCP/IP sera utilisé au lieu d'un socket Unix
     */
    public function setSocket(string $socket): self
    {
        // Toujours forcer le socket à être vide pour utiliser TCP/IP
        return parent::setSocket('');
    }
    
    /**
     * Override getContentsOfCredentialsFile pour s'assurer que host est toujours défini
     * et que socket n'est jamais utilisé
     */
    public function getContentsOfCredentialsFile(): string
    {
        // Forcer le socket à être vide avant de générer le fichier de credentials
        $this->socket = '';
        
        $contents = [
            '[client]',
            "user = '{$this->userName}'",
            "password = '{$this->password}'",
            "port = '{$this->port}'",
            "host = '{$this->host}'", // Toujours définir host pour forcer TCP/IP
        ];

        if ($this->skipSsl) {
            $contents[] = "skip-ssl";
        }

        return implode(PHP_EOL, $contents);
    }
    
    /**
     * Override getDumpCommand pour s'assurer que --socket n'est jamais ajouté
     * et que --host est toujours présent pour forcer TCP/IP
     */
    public function getDumpCommand(string $dumpFile, string $temporaryCredentialsFile): string
    {
        // Forcer le socket à être vide
        $this->socket = '';
        
        $quote = $this->determineQuote();

        $command = [
            "{$quote}{$this->dumpBinaryPath}mysqldump{$quote}",
            "--defaults-extra-file=\"{$temporaryCredentialsFile}\"",
        ];
        
        // Appeler getCommonDumpCommand pour ajouter les autres options
        $finalDumpCommand = $this->getCommonDumpCommand($command);
        
        // Retirer toute option --socket qui pourrait avoir été ajoutée
        $finalDumpCommand = preg_replace('/--socket=[^\s]+/', '', $finalDumpCommand);
        
        // Ajouter --host APRÈS --defaults-extra-file pour forcer TCP/IP
        // On doit trouver --defaults-extra-file="..." et ajouter --host juste après
        if (!empty($this->host) && strpos($finalDumpCommand, '--host') === false) {
            $finalDumpCommand = preg_replace(
                '/(--defaults-extra-file="[^"]+")/',
                '$1 --host=' . escapeshellarg($this->host),
                $finalDumpCommand,
                1 // Limiter à 1 remplacement
            );
        }
        
        // Logger pour debug
        \Log::info('MySqlForcedTcp::getDumpCommand', [
            'command_before' => $this->getCommonDumpCommand($command),
            'command_after' => $finalDumpCommand,
            'host' => $this->host,
        ]);
        
        return $this->echoToFile($finalDumpCommand, $dumpFile);
    }
    
    /**
     * Override getCommonDumpCommand pour s'assurer que --socket n'est jamais ajouté
     */
    public function getCommonDumpCommand(array $command): string
    {
        // Forcer le socket à être vide avant d'appeler la méthode parente
        $this->socket = '';
        
        // Appeler la méthode parente
        $parentCommand = parent::getCommonDumpCommand($command);
        
        // Retirer toute option --socket qui pourrait avoir été ajoutée
        $parentCommand = preg_replace('/--socket=[^\s]+/', '', $parentCommand);
        
        return $parentCommand;
    }
    
    /**
     * Override getProcess pour forcer TCP/IP avec des variables d'environnement
     * et s'assurer que la commande utilise explicitement --protocol=TCP
     */
    public function getProcess(string $dumpFile): Process
    {
        // Forcer le socket à être vide
        $this->socket = '';
        
        // Appeler la méthode parente
        $process = parent::getProcess($dumpFile);
        
        // Récupérer la commande et forcer --protocol=TCP
        $commandLine = $process->getCommandLine();
        
        // Sur Windows, forcer TCP/IP avec --protocol=TCP
        // Ne pas utiliser PIPE car MySQL essaie d'utiliser un socket Unix même sur Windows
        if (strpos($commandLine, '--protocol') === false && !empty($this->host)) {
            // Utiliser TCP/IP avec --protocol=TCP
            $commandLine = preg_replace(
                '/(--host=[^\s]+)/',
                '$1 --protocol=TCP',
                $commandLine,
                1
            );
        }
        
        // Sur Windows, s'assurer que nous n'utilisons pas de socket Unix
        // Retirer toute référence à un socket Unix
        $commandLine = preg_replace('/--socket=[^\s]+/', '', $commandLine);
        
        // Recréer le processus avec la commande modifiée
        $process = Process::fromShellCommandline($commandLine, null, null, null, $this->timeout);
        
        // Forcer TCP/IP en définissant des variables d'environnement
        $env = $process->getEnv();
        $env['MYSQL_HOST'] = $this->host ?? '127.0.0.1';
        $env['MYSQL_PORT'] = (string)($this->port ?? 3306);
        // Ne pas définir MYSQL_UNIX_PORT pour forcer TCP/IP
        unset($env['MYSQL_UNIX_PORT']);
        
        $process->setEnv($env);
        
        // Logger la commande pour debug
        \Log::info('MySqlForcedTcp::getProcess', [
            'command' => $process->getCommandLine(),
            'env' => $env,
            'host' => $this->host,
            'port' => $this->port,
            'socket' => $this->socket,
        ]);
        
        return $process;
    }
}

