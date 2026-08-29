<?php

namespace App\Database;

use RuntimeException;

/**
 * Loads privileged MySQL credentials from local secret files (never committed to Git).
 */
class PrivilegedCredentialLoader
{
    public const BACKUP_CREDENTIAL_FILE = '.mysql-gestion-backup.local';

    /**
     * Keys allowed in credential files (password never logged or returned in exceptions).
     *
     * @var list<string>
     */
    private const ALLOWED_KEYS = [
        'DB_USERNAME',
        'DB_PASSWORD',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
    ];

    /**
     * @return array{username: string, password: string, host: string, port: ?string, database: string, source: string}
     */
    public function loadBackupCredentials(?string $path = null): array
    {
        $path ??= base_path(self::BACKUP_CREDENTIAL_FILE);

        $this->assertCredentialFileAllowed($path);

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(
                'DATABASE SAFETY BLOCK: backup credential file is missing or unreadable. '.
                'Expected '.self::BACKUP_CREDENTIAL_FILE.' at the project root (gitignored).',
            );
        }

        $parsed = $this->parseCredentialFile($path);

        $username = trim($parsed['DB_USERNAME'] ?? '');
        $password = $parsed['DB_PASSWORD'] ?? '';
        $database = trim($parsed['DB_DATABASE'] ?? '');
        $host = trim($parsed['DB_HOST'] ?? '127.0.0.1');
        $port = isset($parsed['DB_PORT']) ? trim((string) $parsed['DB_PORT']) : null;

        $expectedUser = DatabaseAccountGuard::backupAccountName();

        if ($username === '' || strcasecmp($username, $expectedUser) !== 0) {
            throw new RuntimeException(
                "DATABASE SAFETY BLOCK: backup credential file must define DB_USERNAME={$expectedUser}.",
            );
        }

        if ($password === '') {
            throw new RuntimeException(
                'DATABASE SAFETY BLOCK: backup credential file must define DB_PASSWORD (value not shown).',
            );
        }

        if ($database === '') {
            throw new RuntimeException(
                'DATABASE SAFETY BLOCK: backup credential file must define DB_DATABASE.',
            );
        }

        return [
            'username' => $username,
            'password' => $password,
            'host' => $host,
            'port' => $port !== '' ? $port : null,
            'database' => $database,
            'source' => basename($path),
        ];
    }

    public static function sanitizeForLog(string $text, string $secret): string
    {
        if ($secret === '') {
            return $text;
        }

        return str_replace($secret, '[REDACTED]', $text);
    }

    /**
     * @return array<string, string>
     */
    public function parseCredentialFile(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException('DATABASE SAFETY BLOCK: unable to read backup credential file.');
        }

        $values = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($value !== '' && (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            )) {
                $value = substr($value, 1, -1);
            }

            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                continue;
            }

            $values[$key] = $value;
        }

        return $values;
    }

    private function assertCredentialFileAllowed(string $path): void
    {
        $basename = basename($path);

        if ($basename !== self::BACKUP_CREDENTIAL_FILE) {
            throw new RuntimeException(
                'DATABASE SAFETY BLOCK: unexpected backup credential filename.',
            );
        }

        $gitignorePath = base_path('.gitignore');

        if (is_readable($gitignorePath)) {
            $gitignore = file_get_contents($gitignorePath);

            if (is_string($gitignore) && ! str_contains($gitignore, self::BACKUP_CREDENTIAL_FILE)) {
                throw new RuntimeException(
                    'DATABASE SAFETY BLOCK: '.self::BACKUP_CREDENTIAL_FILE.' must remain gitignored.',
                );
            }
        }
    }
}
