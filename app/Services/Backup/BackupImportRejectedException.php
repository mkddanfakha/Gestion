<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Controlled import rejection with a stable machine-readable code (logs / audit).
 */
final class BackupImportRejectedException extends RuntimeException
{
    public const UPLOAD_FAILED = 'UPLOAD_FAILED';

    public const INVALID_EXTENSION = 'INVALID_EXTENSION';

    public const UPLOAD_TOO_LARGE = 'UPLOAD_TOO_LARGE';

    public const UPLOAD_EMPTY = 'UPLOAD_EMPTY';

    public const QUARANTINE_FAILED = 'QUARANTINE_FAILED';

    public const INVALID_ZIP = 'INVALID_ZIP';

    public const INVALID_ARCHIVE = 'INVALID_ARCHIVE';

    public const INVALID_NO_SQL = 'INVALID_NO_SQL';

    public const INVALID_TOO_SMALL = 'INVALID_TOO_SMALL';

    public const ZIP_SECURITY = 'ZIP_SECURITY';

    public const DUPLICATE_BACKUP = 'DUPLICATE_BACKUP';

    public const PROMOTION_FAILED = 'PROMOTION_FAILED';

    public const MANIFEST_FAILED = 'MANIFEST_FAILED';

    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
