<?php

namespace App\Services\Restore;

use RuntimeException;

/**
 * Controlled restore rejection with a stable machine-readable code (logs / audit / UI).
 */
final class BackupRestoreRejectedException extends RuntimeException
{
    public const CREDENTIALS_MISSING = 'RESTORE_CREDENTIALS_MISSING';

    public const CREDENTIALS_INVALID = 'RESTORE_CREDENTIALS_INVALID';

    public const DATABASE_NOT_ALLOWED = 'RESTORE_DATABASE_NOT_ALLOWED';

    public const BACKUP_NOT_FOUND = 'RESTORE_BACKUP_NOT_FOUND';

    public const BACKUP_INVALID = 'RESTORE_BACKUP_INVALID';

    public const SQL_NOT_FOUND = 'RESTORE_SQL_NOT_FOUND';

    public const SQL_INVALID = 'RESTORE_SQL_INVALID';

    public const INTEGRITY_FAILED = 'RESTORE_BACKUP_INTEGRITY_FAILED';

    public const PROCESS_FAILED = 'RESTORE_PROCESS_FAILED';

    public const LOCKED = 'RESTORE_LOCKED';

    public const CONFIRMATION_FAILED = 'RESTORE_CONFIRMATION_FAILED';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromRuntime(string $errorCode, RuntimeException $previous): self
    {
        return new self($errorCode, $previous->getMessage(), $previous);
    }
}
