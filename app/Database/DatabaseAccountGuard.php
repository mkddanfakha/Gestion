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

        if (config('database-accounts.env_cutover_executed') === true
            && ! self::isRuntimeUsername($username)
            && $username !== ''
        ) {
            throw ProtectedDatabaseException::forRuntimeAccountMismatch(
                $username,
                self::runtimeAccountName(),
            );
        }
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

        return [
            'runtime_account' => self::runtimeAccountName(),
            'backup_account' => self::backupAccountName(),
            'restore_account' => self::restoreAccountName(),
            'migration_account' => self::migrationAccountName(),
            'configured_mysql_username' => $username === '' ? null : $username,
            'runtime_is_root' => self::isRootUsername($username),
            'runtime_is_privileged' => self::isPrivilegedUsername($username),
            'runtime_matches_policy' => self::isRuntimeUsername($username),
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
