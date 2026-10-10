<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Raised when a backup operation cannot be matched to exactly one archive on disk.
 */
final class BackupArchiveResolutionException extends RuntimeException
{
    public const AMBIGUOUS = 'ambiguous';

    public function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }
}
