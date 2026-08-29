<?php

namespace App\Services\Restore;

use App\Database\DatabaseAccountGuard;
use App\Database\DatabaseSafetyGuard;
use PDO;
use RuntimeException;

/**
 * Imports a SQL dump into an explicit allow-listed database.
 * Host credentials come from config; the target name never comes from DB_DATABASE.
 * Requires dedicated restore account (never gestion_app / root runtime).
 */
class MysqlPdoDumpImporter implements SqlDumpImporter
{
    public function import(string $sqlPath, string $explicitTarget): void
    {
        $target = DatabaseSafetyGuard::assertExplicitRestoreTarget($explicitTarget);
        DatabaseAccountGuard::assertAccountForOperation(DatabaseAccountGuard::OPERATION_RESTORE);

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

        // Never honour dump-level database switches (would risk writing outside the explicit target).
        $sql = preg_replace('/^\s*USE\s+[`\'"]?[^;`\'"]+[`\'"]?\s*;/mi', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*CREATE\s+DATABASE\b[^;]*;/mi', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*DROP\s+DATABASE\b[^;]*;/mi', '', $sql) ?? $sql;
        // mysqldump LOCK TABLES requires LOCK TABLES grant; gestion_restore is DDL/DML-only on allow-listed DBs.
        $sql = preg_replace('/^\s*LOCK\s+TABLES\b[^;]*;/mi', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*UNLOCK\s+TABLES\s*;/mi', '', $sql) ?? $sql;

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

        $this->runStatements($pdo, $sql);
    }

    private function runStatements(PDO $pdo, string $sql): void
    {
        foreach ($this->splitStatements($sql) as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * Split on ';' outside of quotes / backticks so session payloads with ';' stay intact.
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $len = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $prev = $i > 0 ? $sql[$i - 1] : '';

            if ($inSingle) {
                if ($ch === "'" && $prev !== '\\') {
                    // MySQL escape: '' inside string
                    if ($i + 1 < $len && $sql[$i + 1] === "'") {
                        $buffer .= "''";
                        $i++;
                        continue;
                    }
                    $inSingle = false;
                }
                $buffer .= $ch;
                continue;
            }

            if ($inDouble) {
                if ($ch === '"' && $prev !== '\\') {
                    $inDouble = false;
                }
                $buffer .= $ch;
                continue;
            }

            if ($inBacktick) {
                if ($ch === '`') {
                    $inBacktick = false;
                }
                $buffer .= $ch;
                continue;
            }

            if ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
                // Line comment until newline
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            if ($ch === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                $i += 2;
                while ($i + 1 < $len && ! ($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                $i++; // consume '/'
                continue;
            }

            if ($ch === "'") {
                $inSingle = true;
                $buffer .= $ch;
                continue;
            }
            if ($ch === '"') {
                $inDouble = true;
                $buffer .= $ch;
                continue;
            }
            if ($ch === '`') {
                $inBacktick = true;
                $buffer .= $ch;
                continue;
            }

            if ($ch === ';') {
                $statement = trim($buffer);
                $buffer = '';
                if ($statement !== '' && ! str_starts_with($statement, '/*')) {
                    $statements[] = $statement;
                }
                continue;
            }

            $buffer .= $ch;
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }
}
