<?php

namespace App\Database;

use Illuminate\Support\Facades\Config;

/**
 * PRE-PROD 9.3 — Strict separation of MySQL account roles.
 * PRE-PROD 9.5.2E — Runtime allow decision is (database, username) pairs only.
 * Runtime must never load privileged backup/restore/migration credentials via DB_USERNAME.
 */
class DatabaseAccountGuard
{
    public const OPERATION_BACKUP = 'backup';

    public const OPERATION_RESTORE = 'restore';

    public const OPERATION_MIGRATION = 'migration';

    /**
     * Hardcoded fail-closed defaults when config is missing or malformed.
     *
     * @var list<array{database: string, username: string}>
     */
    private const DEFAULT_RUNTIME_ALLOWED_PAIRS = [];

    /**
     * @var list<array{database: string, username: string}>
     */
    private const DEFAULT_MIGRATION_ALLOWED_PAIRS = [];

    public static function mysqlUsername(): string
    {
        $username = Config::get('database.connections.mysql.username');

        return is_string($username) ? trim($username) : '';
    }

    public static function mysqlDatabaseName(): string
    {
        $database = Config::get('database.connections.mysql.database');

        return is_string($database) ? trim($database) : '';
    }

    public static function runtimeAccountName(): string
    {
        return self::configAccount('runtime_account', 'gestion_app');
    }

    public static function backupAccountName(): string
    {
        return self::configAccount('backup_account', 'gestion_backup');
    }

    public static function restoreAccountName(): string
    {
        return self::configAccount('restore_account', 'gestion_restore');
    }

    public static function migrationAccountName(): string
    {
        return self::configAccount('migration_account', 'gestion_migration');
    }

    /**
     * Exact (database, username) pairs allowed as Laravel mysql runtime after cutover.
     *
     * @return list<array{database: string, username: string}>
     */
    public static function runtimeAllowedPairs(): array
    {
        return self::normalizePairs(
            config('database-accounts.runtime_allowed_pairs', self::DEFAULT_RUNTIME_ALLOWED_PAIRS),
            self::DEFAULT_RUNTIME_ALLOWED_PAIRS,
        );
    }

    /**
     * Exact (database, username) pairs allowed for mysql `migrate` after cutover.
     *
     * @return list<array{database: string, username: string}>
     */
    public static function migrationAllowedPairs(): array
    {
        return self::normalizePairs(
            config('database-accounts.migration_allowed_pairs', self::DEFAULT_MIGRATION_ALLOWED_PAIRS),
            self::DEFAULT_MIGRATION_ALLOWED_PAIRS,
        );
    }

    /**
     * @return list<string>
     */
    public static function privilegedAccountNames(): array
    {
        return array_values(array_unique([
            self::backupAccountName(),
            self::restoreAccountName(),
            self::migrationAccountName(),
        ]));
    }

    public static function isRootUsername(?string $username): bool
    {
        if ($username === null || $username === '') {
            return false;
        }

        return strcasecmp(trim($username), 'root') === 0;
    }

    public static function isPrivilegedUsername(?string $username): bool
    {
        if ($username === null || $username === '') {
            return false;
        }

        $name = trim($username);
        foreach (self::privilegedAccountNames() as $privileged) {
            if (strcasecmp($name, $privileged) === 0) {
                return true;
            }
        }

        return false;
    }

    public static function isRuntimeUsername(?string $username): bool
    {
        if ($username === null || $username === '') {
            return false;
        }

        return strcasecmp(trim($username), self::runtimeAccountName()) === 0;
    }

    /**
     * Fail-closed: both database and username must match one configured pair exactly
     * (case-insensitive). Empty values never match. Username alone is never enough.
     */
    public static function isAllowedRuntimePair(?string $database, ?string $username): bool
    {
        if ($database === null || $username === null) {
            return false;
        }

        $database = trim($database);
        $username = trim($username);

        if ($database === '' || $username === '') {
            return false;
        }

        foreach (self::runtimeAllowedPairs() as $pair) {
            if (strcasecmp($database, $pair['database']) === 0
                && strcasecmp($username, $pair['username']) === 0
            ) {
                return true;
            }
        }

        return false;
    }

    public static function isAllowedMigrationPair(?string $database, ?string $username): bool
    {
        if ($database === null || $username === null) {
            return false;
        }

        $database = trim($database);
        $username = trim($username);

        if ($database === '' || $username === '') {
            return false;
        }

        foreach (self::migrationAllowedPairs() as $pair) {
            if (strcasecmp($database, $pair['database']) === 0
                && strcasecmp($username, $pair['username']) === 0
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when this PHP process was spawned as a privileged subprocess (e.g. backup:run under gestion_backup).
     * Runtime account guard is skipped; PrivilegedCommandGuard still applies.
     */
    public static function isPrivilegedSubprocess(): bool
    {
        $marker = getenv(PrivilegedProcessRunner::SUBPROCESS_MARKER);

        return is_string($marker) && trim($marker) !== '';
    }

    public static function isRestoreSubprocess(): bool
    {
        if (! self::isPrivilegedSubprocess()) {
            return false;
        }

        $marker = getenv(PrivilegedProcessRunner::SUBPROCESS_MARKER);

        return is_string($marker)
            && strcasecmp(trim($marker), PrivilegedProcessRunner::SUBPROCESS_OPERATION_RESTORE) === 0;
    }

    /**
     * Laravel mysql runtime must match an allowed (database, username) pair after cutover
     * (not root, not privileged). Pair policy is fail-closed.
     */
    public static function assertRuntimeUsernameAllowed(?string $username = null, ?string $database = null): void
    {
        $username ??= self::mysqlUsername();
        $database ??= self::mysqlDatabaseName();

        if (self::isRootUsername($username)) {
            throw ProtectedDatabaseException::forRuntimeRootAccount($username);
        }

        if (self::isPrivilegedUsername($username)) {
            throw ProtectedDatabaseException::forRuntimePrivilegedAccount(
                $username,
                self::runtimeAccountName(),
            );
        }

        if (config('database-accounts.env_cutover_executed') !== true) {
            return;
        }

        if (! self::isAllowedRuntimePair($database, $username)) {
            throw ProtectedDatabaseException::forRuntimeAccountMismatch(
                $username === '' ? '(empty)' : $username,
                self::runtimeAccountName(),
                $database === '' ? '(empty)' : $database,
            );
        }
    }

    /**
     * Fail-closed privileged Artisan operations.
     * Backup / restore: dedicated username only.
     * Migration: exact (database, username) pair — never username-only.
     */
    public static function assertAccountForOperation(
        string $operation,
        ?string $username = null,
        ?string $database = null,
    ): void {
        $username ??= self::mysqlUsername();
        $database ??= self::mysqlDatabaseName();

        if ($operation === self::OPERATION_MIGRATION) {
            if (! self::isAllowedMigrationPair($database, $username)) {
                throw ProtectedDatabaseException::forMigrationPairMismatch(
                    $username === '' ? '(empty)' : $username,
                    $database === '' ? '(empty)' : $database,
                );
            }

            return;
        }

        $expected = match ($operation) {
            self::OPERATION_BACKUP => self::backupAccountName(),
            self::OPERATION_RESTORE => self::restoreAccountName(),
            default => throw new \InvalidArgumentException("Unknown database account operation: {$operation}"),
        };

        if ($username === '' || strcasecmp($username, $expected) !== 0) {
            throw ProtectedDatabaseException::forPrivilegedOperationAccountMismatch(
                $operation,
                $username === '' ? '(empty)' : $username,
                $expected,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        $username = self::mysqlUsername();
        $database = self::mysqlDatabaseName();

        return [
            'runtime_account' => self::runtimeAccountName(),
            'backup_account' => self::backupAccountName(),
            'restore_account' => self::restoreAccountName(),
            'migration_account' => self::migrationAccountName(),
            'configured_mysql_database' => $database === '' ? null : $database,
            'configured_mysql_username' => $username === '' ? null : $username,
            'runtime_is_root' => self::isRootUsername($username),
            'runtime_is_privileged' => self::isPrivilegedUsername($username),
            'runtime_matches_policy' => self::isAllowedRuntimePair($database, $username),
            'runtime_allowed_pairs' => self::runtimeAllowedPairs(),
            'migration_allowed_pairs' => self::migrationAllowedPairs(),
            'backup_account_created' => (bool) config('database-accounts.backup_account_created', false),
            'restore_account_created' => (bool) config('database-accounts.restore_account_created', false),
            'migration_account_created' => (bool) config('database-accounts.migration_account_created', false),
            'password_exposed' => false,
        ];
    }

    private static function configAccount(string $key, string $default): string
    {
        $value = config('database-accounts.'.$key, $default);

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * @param  mixed  $configured
     * @param  list<array{database: string, username: string}>  $fallback
     * @return list<array{database: string, username: string}>
     */
    private static function normalizePairs(mixed $configured, array $fallback): array
    {
        if (is_string($configured)) {
            $configured = trim($configured);
            if ($configured === '') {
                return $fallback;
            }
            $pairs = [];

            foreach (explode(',', $configured) as $rawPair) {
                $rawPair = trim($rawPair);

                if ($rawPair === '' || ! str_contains($rawPair, ':')) {
                    continue;
                }
                [$database, $username] = array_map('trim', explode(':', $rawPair, 2));

                if ($database === '' || $username === '') {
                    continue;
                }

                $pairs[] = [
                    'database' => $database,
                    'username' => $username,
                ];
            }

            return $pairs === [] ? $fallback : $pairs;
        }

        if (! is_array($configured) || $configured === []) {
            return $fallback;
        }

        $pairs = [];

        foreach ($configured as $pair) {
            if (! is_array($pair)) {
                continue;
            }
            $database = isset($pair['database']) && is_string($pair['database'])
                ? trim($pair['database'])
                : '';

            $username = isset($pair['username']) && is_string($pair['username'])
                ? trim($pair['username'])
                : '';

            if ($database === '' || $username === '') {
                continue;
            }

            $pairs[] = [
                'database' => $database,
                'username' => $username,
            ];
        }
        return $pairs === [] ? $fallback : $pairs;
    }
}
