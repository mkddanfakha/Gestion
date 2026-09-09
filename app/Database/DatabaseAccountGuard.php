<?php

namespace App\Database;

use Illuminate\Support\Facades\Config;

/**
 * PRE-PROD 9.3 — Strict separation of MySQL account roles.
 * Runtime must never load privileged backup/restore/migration credentials via DB_USERNAME.
 */
class DatabaseAccountGuard
{
    public const OPERATION_BACKUP = 'backup';

    public const OPERATION_RESTORE = 'restore';

    public const OPERATION_MIGRATION = 'migration';

    public static function mysqlUsername(): string
    {
        $username = Config::get('database.connections.mysql.username');

        return is_string($username) ? trim($username) : '';
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
     * Laravel runtime mysql username must be gestion_app (not root, not privileged).
     */
    public static function assertRuntimeUsernameAllowed(?string $username = null): void
    {
        $username ??= self::mysqlUsername();

        if (self::isRootUsername($username)) {
            throw ProtectedDatabaseException::forRuntimeRootAccount($username);
        }

        if (self::isPrivilegedUsername($username)) {
            throw ProtectedDatabaseException::forRuntimePrivilegedAccount(
                $username,
                self::runtimeAccountName(),
            );
        }

        $isConfiguredRuntime = self::isRuntimeUsername($username);

        $isAllowedSharedHostingRuntime = $username !== ''
            && self::isMigrationPairAllowed($username);

        if (
            config('database-accounts.env_cutover_executed') === true
            && ! $isConfiguredRuntime
            && ! $isAllowedSharedHostingRuntime
            && $username !== ''
        ) {
            throw ProtectedDatabaseException::forRuntimeAccountMismatch(
                $username,
                self::runtimeAccountName(),
            );
        }
    }

    /**
     * Check whether the current MySQL database/user pair is explicitly
     * authorized to run migrations (e.g. OVH shared hosting).
     *
     * Format:
     * DB_MIGRATION_ALLOWED_PAIRS=database:username,database2:username2
     */
    public static function isMigrationPairAllowed(?string $username = null, ?string $database = null): bool
    {
        $username ??= self::mysqlUsername();
        $database ??= self::mysqlDatabaseName();

        if ($username === '' || $database === '') {
            return false;
        }

        if (! DatabaseSafetyGuard::isProtectedDatabase($database)) {
            return false;
        }

        $configured = config('database-accounts.migration_allowed_pairs', '');

        if (! is_string($configured) || trim($configured) === '') {
            return false;
        }

        foreach (explode(',', $configured) as $pair) {
            $pair = trim($pair);

            if ($pair === '' || ! str_contains($pair, ':')) {
                continue;
            }

            [$allowedDatabase, $allowedUsername] = array_map('trim', explode(':', $pair, 2));

            if (
                $allowedDatabase !== ''
                && $allowedUsername !== ''
                && strcasecmp($database, $allowedDatabase) === 0
                && strcasecmp($username, $allowedUsername) === 0
            ) {
                return true;
            }
        }

        return false;
    }

    public static function mysqlDatabaseName(): string
    {
        $database = Config::get('database.connections.mysql.database');

        return is_string($database) ? trim($database) : '';
    }

    /**
     * Fail-closed: privileged Artisan operations must use the dedicated account.
     */
    public static function assertAccountForOperation(string $operation, ?string $username = null): void
    {
        $username ??= self::mysqlUsername();
        $expected = match ($operation) {
            self::OPERATION_BACKUP => self::backupAccountName(),
            self::OPERATION_RESTORE => self::restoreAccountName(),
            self::OPERATION_MIGRATION => self::migrationAccountName(),
            default => throw new \InvalidArgumentException("Unknown database account operation: {$operation}"),
        };

        $isExpectedAccount = $username !== ''
            && strcasecmp($username, $expected) === 0;

        $isAllowedMigrationPair = $operation === self::OPERATION_MIGRATION
            && self::isMigrationPairAllowed($username);

        if (! $isExpectedAccount && ! $isAllowedMigrationPair) {
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

        return [
            'runtime_account' => self::runtimeAccountName(),
            'backup_account' => self::backupAccountName(),
            'restore_account' => self::restoreAccountName(),
            'migration_account' => self::migrationAccountName(),
            'configured_mysql_username' => $username === '' ? null : $username,
            'runtime_is_root' => self::isRootUsername($username),
            'runtime_is_privileged' => self::isPrivilegedUsername($username),
            'runtime_matches_policy' => self::isRuntimeUsername($username) || self::isMigrationPairAllowed($username),
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
}
