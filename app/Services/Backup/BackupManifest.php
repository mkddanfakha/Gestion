<?php

namespace App\Services\Backup;

/**
 * Versioned backup manifest (sidecar JSON). Never stores secrets/credentials.
 *
 * @phpstan-type ManifestArray array<string, mixed>
 */
final class BackupManifest
{
    public const VERSION = 1;

    public const INTEGRITY_VALID = 'VALID';

    public const INTEGRITY_INVALID = 'INVALID';

    public const INTEGRITY_MISSING = 'MISSING';

    public const INTEGRITY_MANIFEST_INVALID = 'MANIFEST_INVALID';

    public const COMPAT_COMPATIBLE = 'Compatible';

    public const COMPAT_REVIEW = 'Compatibilité à vérifier';

    public const COMPAT_INCOMPATIBLE = 'Incompatible';

    /**
     * @param  array<string, mixed>  $attributes
     * @return ManifestArray
     */
    public static function build(array $attributes): array
    {
        $type = BackupMetadataService::normalizeTypePublic($attributes['type'] ?? BackupMetadataService::TYPE_DATABASE);
        $source = BackupMetadataService::normalizeSourcePublic($attributes['source'] ?? BackupMetadataService::SOURCE_MANUAL);
        $status = BackupMetadataService::normalizeStatusPublic($attributes['status'] ?? BackupMetadataService::STATUS_VALID);
        $filename = isset($attributes['filename'])
            ? BackupPathGuard::sanitizeBackupFileName((string) $attributes['filename'])
            : null;

        $sha256 = isset($attributes['sha256']) && is_string($attributes['sha256']) && $attributes['sha256'] !== ''
            ? strtolower($attributes['sha256'])
            : null;
        $sizeBytes = isset($attributes['size_bytes']) ? (int) $attributes['size_bytes'] : null;

        $filesIncluded = array_key_exists('files_included', $attributes)
            ? (bool) $attributes['files_included']
            : ($type === BackupMetadataService::TYPE_FULL);

        $dbIncluded = array_key_exists('database_included', $attributes)
            ? (bool) $attributes['database_included']
            : true;

        $importedAt = $attributes['imported_at'] ?? null;
        if ($importedAt === null && $source === BackupMetadataService::SOURCE_IMPORT) {
            $importedAt = $attributes['created_at'] ?? now()->toIso8601String();
        }

        $appVersion = $attributes['application_version'] ?? null;
        if ($appVersion !== null) {
            $appVersion = is_string($appVersion) ? $appVersion : null;
        }

        $environment = $attributes['environment'] ?? null;
        if ($environment === null) {
            $env = config('app.env');
            $environment = is_string($env) && $env !== '' ? $env : null;
        }

        // Never put credentials; optional logical DB name only if explicitly provided.
        $databaseName = $attributes['database_name'] ?? null;
        if ($databaseName !== null && (! is_string($databaseName) || $databaseName === '')) {
            $databaseName = null;
        }

        $driver = $attributes['database_driver'] ?? null;
        if ($driver === null && $dbIncluded) {
            $driver = 'mysql';
        }

        return [
            'manifest_version' => self::VERSION,
            'backup_format_version' => BackupMetadataService::FORMAT_VERSION,
            'backup_id' => (string) ($attributes['backup_id'] ?? \Illuminate\Support\Str::uuid()),
            'filename' => $filename,
            'type' => $type,
            'source' => $source,
            'status' => $status,
            'created_at' => (string) ($attributes['created_at'] ?? now()->toIso8601String()),
            'imported_at' => is_string($importedAt) ? $importedAt : null,
            'application' => [
                'name' => BackupMetadataService::APPLICATION,
                'version' => $appVersion,
            ],
            // Legacy flat field for older UI readers
            'application_name' => BackupMetadataService::APPLICATION,
            'application_version' => $appVersion,
            'environment' => is_string($environment) ? $environment : null,
            'database' => [
                'included' => $dbIncluded,
                'driver' => $dbIncluded ? (is_string($driver) ? $driver : 'mysql') : null,
                'database_name' => $databaseName,
            ],
            'files' => [
                'included' => $filesIncluded,
            ],
            'archive' => [
                'size_bytes' => $sizeBytes,
                'sha256' => $sha256,
            ],
            // Flat aliases used by listing / older code paths
            'sha256' => $sha256,
            'size_bytes' => $sizeBytes,
            'user_id' => isset($attributes['user_id']) ? (int) $attributes['user_id'] : null,
            'original_filename' => isset($attributes['original_filename'])
                ? (string) $attributes['original_filename']
                : null,
            'verdict' => isset($attributes['verdict']) ? (string) $attributes['verdict'] : null,
        ];
    }

    /**
     * Normalize legacy sidecar (12.7.3) into manifest-shaped array for readers.
     *
     * @param  array<string, mixed>  $raw
     * @return ManifestArray
     */
    public static function normalizeLegacy(array $raw): array
    {
        if (isset($raw['manifest_version'])) {
            return $raw;
        }

        $app = $raw['application'] ?? BackupMetadataService::APPLICATION;
        $appName = is_array($app) ? ($app['name'] ?? BackupMetadataService::APPLICATION) : (string) $app;
        $appVersion = is_array($app)
            ? ($app['version'] ?? $raw['application_version'] ?? null)
            : ($raw['application_version'] ?? null);

        $type = $raw['type'] ?? BackupMetadataService::TYPE_DATABASE;
        $sha = isset($raw['sha256']) && is_string($raw['sha256']) ? strtolower($raw['sha256']) : null;
        $size = isset($raw['size_bytes']) ? (int) $raw['size_bytes'] : null;

        return self::build([
            'backup_id' => $raw['backup_id'] ?? null,
            'filename' => $raw['filename'] ?? null,
            'type' => $type,
            'source' => $raw['source'] ?? BackupMetadataService::SOURCE_MANUAL,
            'status' => $raw['status'] ?? BackupMetadataService::STATUS_VALID,
            'created_at' => $raw['created_at'] ?? now()->toIso8601String(),
            'imported_at' => ($raw['source'] ?? null) === BackupMetadataService::SOURCE_IMPORT
                ? ($raw['created_at'] ?? null)
                : null,
            'application_version' => $appVersion,
            'environment' => $raw['environment'] ?? null,
            'sha256' => $sha,
            'size_bytes' => $size,
            'user_id' => $raw['user_id'] ?? null,
            'original_filename' => $raw['original_filename'] ?? null,
            'verdict' => $raw['verdict'] ?? null,
            'files_included' => strtoupper((string) $type) === BackupMetadataService::TYPE_FULL,
            'database_included' => true,
            'database_name' => null,
        ]);
    }

    /**
     * @param  ManifestArray  $manifest
     */
    public static function assertNoSecrets(array $manifest): void
    {
        $encoded = strtolower(json_encode($manifest, JSON_THROW_ON_ERROR));
        $forbidden = [
            'password',
            'passwd',
            'secret',
            'app_key',
            'db_password',
            'mail_password',
            'pusher_app_secret',
            'aws_secret',
            'private_key',
            'begin rsa',
        ];

        foreach ($forbidden as $needle) {
            if (str_contains($encoded, $needle)) {
                throw new \RuntimeException('Manifest must not contain secrets.');
            }
        }
    }
}
