<?php

namespace App\Database;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DatabaseSafetyGuard
{
    /**
     * @return list<string>
     */
    public static function protectedDatabases(): array
    {
        $configured = config('database-safety.protected_databases', ['gestion']);

        if (! is_array($configured)) {
            return ['gestion'];
        }

        return array_values(array_filter(array_map(
            static fn ($name): string => is_string($name) ? trim($name) : '',
            $configured,
        )));
    }

    /**
     * Databases where application-level DROP DATABASE restore is permitted.
     *
     * @return list<string>
     */
    public static function restoreAllowedDatabases(): array
    {
        $configured = config('database-safety.restore_allowed_databases', [
            'gestion_recovery',
            'gestion_test',
        ]);

        if (! is_array($configured)) {
            return ['gestion_recovery', 'gestion_test'];
        }

        return array_values(array_filter(array_map(
            static fn ($name): string => is_string($name) ? trim($name) : '',
            $configured,
        )));
    }

    /**
     * @return list<string>
     */
    public static function destructiveCommands(): array
    {
        $commands = config('database-safety.destructive_commands', [
            'migrate:fresh',
            'migrate:refresh',
            'migrate:reset',
            'db:wipe',
        ]);

        return is_array($commands) ? array_values($commands) : [];
    }

    public static function restoreConfirmationPhrase(): string
    {
        $phrase = config('database-safety.restore_confirmation_phrase', 'RESTORE');

        return is_string($phrase) && $phrase !== '' ? $phrase : 'RESTORE';
    }

    public static function isDestructiveCommand(?string $command): bool
    {
        if ($command === null || $command === '') {
            return false;
        }

        return in_array($command, self::destructiveCommands(), true);
    }

    /**
     * Resolve the configured database name for a connection (no live query required).
     */
    public static function resolveDatabaseName(?string $connection = null): string
    {
        $connection ??= Config::get('database.default', 'sqlite');

        $name = Config::get("database.connections.{$connection}.database");

        if (is_string($name) && $name !== '') {
            return $name;
        }

        try {
            $resolved = DB::connection($connection)->getDatabaseName();

            return is_string($resolved) ? $resolved : '';
        } catch (\Throwable) {
            return '';
        }
    }

    public static function isProtectedDatabase(?string $databaseName): bool
    {
        if ($databaseName === null || $databaseName === '') {
            return false;
        }

        $name = trim($databaseName);

        // Exact match after trim. Case-insensitive so Windows MySQL (lower_case_table_names)
        // cannot bypass via GESTION / Gestion while still distinguishing gestion_test.
        foreach (self::protectedDatabases() as $protected) {
            if (strcasecmp($name, $protected) === 0) {
                return true;
            }
        }

        return false;
    }

    public static function isRestoreAllowedDatabase(?string $databaseName): bool
    {
        if ($databaseName === null || $databaseName === '') {
            return false;
        }

        $name = trim($databaseName);

        foreach (self::restoreAllowedDatabases() as $allowed) {
            if (strcasecmp($name, $allowed) === 0) {
                return true;
            }
        }

        return false;
    }

    public static function isProtectedConnection(?string $connection = null): bool
    {
        return self::isProtectedDatabase(self::resolveDatabaseName($connection));
    }

    public static function assertDestructiveOperationAllowed(
        string $operation = 'destructive database operation',
        ?string $connection = null,
    ): void {
        $database = self::resolveDatabaseName($connection);

        if (self::isProtectedDatabase($database)) {
            self::logBlocked($operation, $database, 'protected_database');

            throw ProtectedDatabaseException::forDestructiveOperation(
                $database,
                $operation,
                (string) config('app.env', 'unknown'),
            );
        }
    }

    /**
     * Alias for wipe / fresh-style operations.
     */
    public static function assertSafeForWipe(
        string $operation = 'db:wipe',
        ?string $connection = null,
    ): void {
        self::assertDestructiveOperationAllowed($operation, $connection);
    }

    /**
     * Alias for migrate:fresh / refresh / reset style operations.
     */
    public static function assertSafeForDestructiveMigration(
        string $operation = 'destructive migration',
        ?string $connection = null,
    ): void {
        self::assertDestructiveOperationAllowed($operation, $connection);
    }

    /**
     * Trim-only normalization. No case folding, no fuzzy match, never reads .env/DB_DATABASE.
     */
    public static function normalizeExplicitTarget(?string $target): string
    {
        if (! is_string($target)) {
            return '';
        }

        return trim($target);
    }

    public static function isSafeDatabaseIdentifier(string $database): bool
    {
        return (bool) preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $database);
    }

    /**
     * Require an explicit restore target. Never infers from config/DB_DATABASE/.env.
     */
    public static function assertExplicitRestoreTarget(?string $targetDatabase): string
    {
        $database = self::normalizeExplicitTarget($targetDatabase);

        if ($database === '') {
            throw ProtectedDatabaseException::forMissingExplicitRestoreTarget();
        }

        if (! self::isSafeDatabaseIdentifier($database)) {
            throw ProtectedDatabaseException::forInvalidRestoreTarget($database);
        }

        self::assertSafeForRestore($database);
        self::assertSafeForDropDatabase($database);

        return $database;
    }

    /**
     * Block application restore unless the target is explicit, allow-listed, and not protected.
     */
    public static function assertSafeForRestore(?string $targetDatabase = null): void
    {
        $database = self::normalizeExplicitTarget($targetDatabase);

        if ($database === '') {
            self::logBlocked('database.restore', '(empty)', 'missing_explicit_target');

            throw ProtectedDatabaseException::forMissingExplicitRestoreTarget();
        }

        if (self::isProtectedDatabase($database)) {
            self::logBlocked('database.restore', $database, 'protected_database');

            throw ProtectedDatabaseException::forRestore(
                $database,
                (string) config('app.env', 'unknown'),
            );
        }

        if (! self::isRestoreAllowedDatabase($database)) {
            self::logBlocked('database.restore', $database, 'not_in_restore_allowlist');

            throw ProtectedDatabaseException::forRestoreNotAllowed(
                $database,
                self::restoreAllowedDatabases(),
                (string) config('app.env', 'unknown'),
            );
        }
    }

    /**
     * Block DROP DATABASE on protected DBs; only allow-listed recovery/test DBs may be dropped by the app.
     */
    public static function assertSafeForDropDatabase(?string $databaseName = null): void
    {
        $database = $databaseName ?? '';

        if ($database === '') {
            self::logBlocked('DROP DATABASE', '(empty)', 'empty_database_name');

            throw ProtectedDatabaseException::forDropDatabase(
                '(empty)',
                (string) config('app.env', 'unknown'),
            );
        }

        if (self::isProtectedDatabase($database)) {
            self::logBlocked('DROP DATABASE', $database, 'protected_database');

            throw ProtectedDatabaseException::forDropDatabase(
                $database,
                (string) config('app.env', 'unknown'),
            );
        }

        if (! self::isRestoreAllowedDatabase($database)) {
            self::logBlocked('DROP DATABASE', $database, 'not_in_restore_allowlist');

            throw ProtectedDatabaseException::forDropDatabaseNotAllowed(
                $database,
                self::restoreAllowedDatabases(),
                (string) config('app.env', 'unknown'),
            );
        }
    }

    public static function assertRestoreConfirmationPhrase(?string $phrase): void
    {
        $expected = self::restoreConfirmationPhrase();

        if (! is_string($phrase) || $phrase !== $expected) {
            self::logBlocked('database.restore', self::resolveDatabaseName('mysql'), 'invalid_confirmation_phrase');

            throw ProtectedDatabaseException::forInvalidRestoreConfirmation($expected);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function status(?string $connection = null): array
    {
        $connection ??= (string) Config::get('database.default', 'sqlite');
        $database = self::resolveDatabaseName($connection);
        $mysqlDatabase = Config::get('database.connections.mysql.database');
        $mysqlDatabase = is_string($mysqlDatabase) ? $mysqlDatabase : null;
        $protected = self::isProtectedDatabase($database);
        $host = Config::get("database.connections.{$connection}.host");
        $restoreTarget = $mysqlDatabase ?? $database;
        $restoreBlocked = self::isProtectedDatabase($restoreTarget)
            || ! self::isRestoreAllowedDatabase($restoreTarget);

        return [
            'environment' => (string) config('app.env', 'unknown'),
            'connection' => $connection,
            'driver' => Config::get("database.connections.{$connection}.driver"),
            'host' => is_string($host) ? $host : null,
            'database' => $database,
            'mysql_database' => $mysqlDatabase,
            'mysql_username_is_root' => self::isMysqlRuntimeUsingRoot(),
            'mysql_privilege_boundary' => self::isMysqlRuntimeUsingRoot()
                ? 'NOT_IMPLEMENTED'
                : 'CONFIGURED_NON_ROOT',
            'protected' => $protected,
            'status' => $protected ? 'PROTECTED' : 'UNPROTECTED',
            'destructive_migrations' => $protected ? 'BLOCKED' : 'ALLOWED',
            'database_wipe' => $protected ? 'BLOCKED' : 'ALLOWED',
            'restore' => $restoreBlocked ? 'BLOCKED' : 'ALLOWED',
            'restore_target' => $restoreTarget,
            'drop_database' => $restoreBlocked ? 'BLOCKED' : 'ALLOWED',
            'test_isolation' => $protected ? 'REQUIRED' : 'OK',
            'protected_databases' => self::protectedDatabases(),
            'restore_allowed_databases' => self::restoreAllowedDatabases(),
        ];
    }

    /**
     * True when the configured mysql connection username is root (password never returned).
     */
    public static function isMysqlRuntimeUsingRoot(): bool
    {
        $username = Config::get('database.connections.mysql.username');

        return is_string($username) && strcasecmp(trim($username), 'root') === 0;
    }

    /**
     * Read-only privilege-boundary snapshot for audits (no secrets).
     *
     * @return array<string, mixed>
     */
    public static function mysqlBoundaryStatus(): array
    {
        $username = Config::get('database.connections.mysql.username');
        $username = is_string($username) ? trim($username) : '';

        return [
            'laravel_db_connection' => Config::get('database.default'),
            'laravel_db_host' => Config::get('database.connections.mysql.host'),
            'laravel_db_port' => Config::get('database.connections.mysql.port'),
            'laravel_db_database' => Config::get('database.connections.mysql.database'),
            'laravel_db_username' => $username === '' ? null : $username,
            'laravel_uses_mysql_root' => self::isMysqlRuntimeUsingRoot(),
            'protected_by_laravel' => 'YES',
            'protected_by_mysql_least_privilege' => self::isMysqlRuntimeUsingRoot()
                ? 'NOT_YET_IMPLEMENTED'
                : 'LIKELY',
            'recommended_runtime_account' => config('database-accounts.runtime_account', 'gestion_app'),
            'recommended_backup_account' => config('database-accounts.backup_account', 'gestion_backup'),
            'recommended_restore_account' => config('database-accounts.restore_account', 'gestion_restore'),
            'recommended_migration_account' => config('database-accounts.migration_account', 'gestion_migration'),
            'recommended_dba_account' => config('database-accounts.dba_account', 'root'),
            'recommended_test_account' => 'gestion_test_user',
            'account_roles' => \App\Database\DatabaseAccountGuard::status(),
            'password_exposed' => false,
        ];
    }

    private static function logBlocked(string $operation, string $database, string $reason): void
    {
        Log::warning('database.destructive_operation_blocked', [
            'operation' => $operation,
            'database' => $database,
            'reason' => $reason,
            'environment' => config('app.env'),
            'user_id' => auth()->id(),
        ]);
    }
}
