<?php

namespace App\Database;

use RuntimeException;

class ProtectedDatabaseException extends RuntimeException
{
    public static function forDestructiveOperation(string $database, string $operation, ?string $environment = null): self
    {
        $environment ??= (string) config('app.env', 'unknown');

        $message = implode("\n", [
            'DATABASE SAFETY BLOCK',
            'Refusing destructive database operation.',
            '',
            "Operation: {$operation}",
            "Target database: {$database}",
            "Environment: {$environment}",
            '',
            'This database is protected.',
            '',
            'Use an isolated test database instead (e.g. gestion_test or sqlite :memory:).',
            '--force does not bypass this protection.',
        ]);

        return new self($message);
    }

    public static function forRestore(string $database, ?string $environment = null): self
    {
        $environment ??= (string) config('app.env', 'unknown');

        $message = implode("\n", [
            'DATABASE SAFETY BLOCK',
            'Refusing database restore.',
            '',
            "Target database: {$database}",
            "Environment: {$environment}",
            '',
            'Application restore must never DROP/CREATE a protected business database.',
            'Restore into gestion_recovery (or another allow-listed database), validate, then switch manually.',
            'Admin RBAC is not sufficient authorization for DROP DATABASE on a protected database.',
        ]);

        return new self($message);
    }

    public static function forRestoreNotAllowed(string $database, array $allowed, ?string $environment = null): self
    {
        $environment ??= (string) config('app.env', 'unknown');
        $allowedList = $allowed === [] ? '(none)' : implode(', ', $allowed);

        $message = implode("\n", [
            'DATABASE SAFETY BLOCK',
            'Refusing database restore.',
            '',
            "Target database: {$database}",
            "Environment: {$environment}",
            "Allowed restore targets: {$allowedList}",
            '',
            'Fail-closed: unknown databases cannot be dropped/recreated by the application.',
        ]);

        return new self($message);
    }

    public static function forDropDatabase(string $database, ?string $environment = null): self
    {
        $environment ??= (string) config('app.env', 'unknown');

        $message = implode("\n", [
            'DATABASE SAFETY BLOCK',
            'Refusing DROP DATABASE.',
            '',
            "Target database: {$database}",
            "Environment: {$environment}",
            '',
            'DROP DATABASE on a protected business database is forbidden from the application.',
        ]);

        return new self($message);
    }

    public static function forDropDatabaseNotAllowed(string $database, array $allowed, ?string $environment = null): self
    {
        $environment ??= (string) config('app.env', 'unknown');
        $allowedList = $allowed === [] ? '(none)' : implode(', ', $allowed);

        $message = implode("\n", [
            'DATABASE SAFETY BLOCK',
            'Refusing DROP DATABASE.',
            '',
            "Target database: {$database}",
            "Environment: {$environment}",
            "Allowed DROP targets: {$allowedList}",
        ]);

        return new self($message);
    }

    public static function forInvalidRestoreConfirmation(string $expectedPhrase): self
    {
        return new self(implode("\n", [
            'DATABASE SAFETY BLOCK',
            'Refusing database restore.',
            '',
            "Missing or invalid confirmation_phrase (expected exact: {$expectedPhrase}).",
            'Frontend checkboxes alone are not sufficient.',
        ]));
    }

    public static function forMissingExplicitRestoreTarget(): self
    {
        return new self(implode("\n", [
            'DATABASE SAFETY BLOCK',
            'Refusing database restore.',
            '',
            'An explicit --target / target_database is required.',
            'The application default database, DB_DATABASE, and .env must never be used as the restore target.',
        ]));
    }

    public static function forInvalidRestoreTarget(string $database): self
    {
        return new self(implode("\n", [
            'DATABASE SAFETY BLOCK',
            'Refusing database restore.',
            '',
            "Target database: {$database}",
            '',
            'Target must be an exact allow-listed identifier (letters, digits, underscore).',
        ]));
    }

    public static function forRuntimeRootAccount(string $username): self
    {
        return new self(implode("\n", [
            'DATABASE ACCOUNT SAFETY BLOCK',
            'Refusing MySQL root as Laravel runtime.',
            '',
            "Configured username: {$username}",
            'Required runtime account: gestion_app',
            '',
            'Root remains DBA/CLI only. --force does not bypass.',
        ]));
    }

    public static function forRuntimePrivilegedAccount(string $username, string $runtimeAccount): self
    {
        return new self(implode("\n", [
            'DATABASE ACCOUNT SAFETY BLOCK',
            'Refusing privileged MySQL account as Laravel runtime.',
            '',
            "Configured username: {$username}",
            "Required runtime account: {$runtimeAccount}",
            '',
            'Backup / restore / migration accounts must never be wired to DB_USERNAME.',
        ]));
    }

    public static function forRuntimeAccountMismatch(string $username, string $runtimeAccount): self
    {
        return new self(implode("\n", [
            'DATABASE ACCOUNT SAFETY BLOCK',
            'Laravel runtime MySQL username does not match policy.',
            '',
            "Configured username: {$username}",
            "Required runtime account: {$runtimeAccount}",
        ]));
    }

    public static function forPrivilegedOperationAccountMismatch(
        string $operation,
        string $username,
        string $expectedAccount,
    ): self {
        return new self(implode("\n", [
            'DATABASE ACCOUNT SAFETY BLOCK',
            "Refusing privileged operation: {$operation}",
            '',
            "Configured MySQL username: {$username}",
            "Required account: {$expectedAccount}",
            '',
            'gestion_app (CRUD runtime) cannot perform backup / restore / migration DDL.',
            'Create and wire the dedicated account only after explicit human approval.',
            '--force does not bypass.',
        ]));
    }
}
