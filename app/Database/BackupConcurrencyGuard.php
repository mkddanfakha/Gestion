<?php

namespace App\Database;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Backend lock for backup/restore.
 *
 * PRE-PROD 10.1 — locks MUST NOT use the default database cache when the MySQL
 * user is gestion_backup (SELECT/LOCK only). Default lock store is "file".
 *
 * Scope: single application server (file store is local). Multi-node deployments
 * should set BACKUP_LOCK_CACHE_STORE to a shared store (e.g. redis) — never the
 * métier DB connection used by gestion_backup.
 */
class BackupConcurrencyGuard
{
    public const BACKUP_LOCK_KEY = 'mkdpro:lock:backup';

    public const RESTORE_LOCK_KEY = 'mkdpro:lock:restore';

    /** Default TTL for a full Spatie backup run (2 hours). */
    public const BACKUP_TTL_SECONDS = 7200;

    /** Default TTL for a restore into an allow-listed recovery database (30 minutes). */
    public const RESTORE_TTL_SECONDS = 1800;

    public static function lockStoreName(): string
    {
        $configured = config('backup.lock_cache_store', 'file');

        return is_string($configured) && $configured !== '' ? $configured : 'file';
    }

    public static function store(): Repository
    {
        return Cache::store(self::lockStoreName());
    }

    public static function acquireBackup(int $ttlSeconds = self::BACKUP_TTL_SECONDS): void
    {
        self::acquire(self::BACKUP_LOCK_KEY, $ttlSeconds, 'backup');
    }

    public static function releaseBackup(): void
    {
        self::store()->forget(self::BACKUP_LOCK_KEY);
    }

    public static function acquireRestore(int $ttlSeconds = self::RESTORE_TTL_SECONDS): void
    {
        self::acquire(self::RESTORE_LOCK_KEY, $ttlSeconds, 'restore');
    }

    public static function releaseRestore(): void
    {
        self::store()->forget(self::RESTORE_LOCK_KEY);
    }

    public static function isBackupLocked(): bool
    {
        return self::store()->has(self::BACKUP_LOCK_KEY);
    }

    public static function isRestoreLocked(): bool
    {
        return self::store()->has(self::RESTORE_LOCK_KEY);
    }

    public static function runBackup(callable $callback, int $ttlSeconds = self::BACKUP_TTL_SECONDS): mixed
    {
        self::acquireBackup($ttlSeconds);

        try {
            return $callback();
        } finally {
            self::releaseBackup();
        }
    }

    public static function runRestore(callable $callback, int $ttlSeconds = self::RESTORE_TTL_SECONDS): mixed
    {
        self::acquireRestore($ttlSeconds);

        try {
            return $callback();
        } finally {
            self::releaseRestore();
        }
    }

    private static function acquire(string $key, int $ttlSeconds, string $operation): void
    {
        $payload = [
            'operation' => $operation,
            'acquired_at' => now()->toIso8601String(),
            'expires_in' => $ttlSeconds,
            'store' => self::lockStoreName(),
        ];

        if (! self::store()->add($key, $payload, $ttlSeconds)) {
            throw new RuntimeException(
                "DATABASE SAFETY BLOCK\n".
                "A {$operation} operation is already in progress.\n".
                'Concurrent backup/restore is forbidden. Retry after the lock expires (TTL '.$ttlSeconds.'s).',
            );
        }
    }
}
