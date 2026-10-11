<?php

namespace App\Services\Restore;

use App\Database\DatabaseAccountGuard;
use App\Database\DatabaseSafetyGuard;
use App\Database\RestoreDatabaseTargetResolver;
use PDO;
use RuntimeException;

/**
 * Imports a SQL dump into an explicit allow-listed database.
 * Host credentials come from config; the target name never comes from DB_DATABASE.
 * Requires dedicated restore account (never gestion_app / root runtime).
 */
class MysqlPdoDumpImporter implements SqlDumpImporter
{
    public function __construct(
        private ?SqlDumpStatementSplitter $statementSplitter = null,
    ) {
        $this->statementSplitter ??= new SqlDumpStatementSplitter;
    }

    public function import(string $sqlPath, string $explicitTarget): void
    {
        if (! is_file($sqlPath)) {
            throw new RuntimeException('SQL dump file not found.');
        }

        $sql = file_get_contents($sqlPath);
        if (! is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('SQL dump is empty or unreadable.');
        }

        if (preg_match('/DROP\s+DATABASE(?:\s+IF\s+EXISTS)?\s+[`\'"]?gestion[`\'"]?(?!_)/i', $sql)) {
            throw new RuntimeException('Dump contains DROP DATABASE gestion — refused.');
        }

        $statements = $this->statementSplitter->split($sql);
        $this->statementSplitter->validateExecutableStatements($statements);

        $logicalTarget = DatabaseSafetyGuard::assertExplicitRestoreTarget($explicitTarget);
        $target = app(RestoreDatabaseTargetResolver::class)->resolvePhysicalName($logicalTarget);
        DatabaseAccountGuard::assertAccountForOperation(DatabaseAccountGuard::OPERATION_RESTORE);

        $mysql = config('database.connections.mysql', []);
        $host = is_array($mysql) ? (string) ($mysql['host'] ?? '127.0.0.1') : '127.0.0.1';
        $port = is_array($mysql) ? (int) ($mysql['port'] ?? 3306) : 3306;
        $user = is_array($mysql) ? (string) ($mysql['username'] ?? '') : '';
        $password = is_array($mysql) ? (string) ($mysql['password'] ?? '') : '';

        $pdo = new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 300,
            ],
        );

        // Allow-listed target only (never gestion).
        $pdo->exec("DROP DATABASE IF EXISTS `{$target}`");
        $pdo->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$target}`");

        $this->runStatements($pdo, $statements);
    }

    /**
     * @param  list<string>  $statements
     */
    private function runStatements(PDO $pdo, array $statements): void
    {
        foreach ($statements as $statement) {
            if ($this->statementSplitter->shouldSkipStatement($statement)) {
                continue;
            }

            $pdo->exec($statement);
        }
    }
}
