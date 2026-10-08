<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Per-installation backup storage prefix: gestion/{MKD_PRO_INSTALLATION_KEY}.
 *
 * Never falls back to APP_NAME, APP_URL, or legacy Gestion/.
 */
final class BackupInstallationKey
{
    public const ENV_VARIABLE = 'MKD_PRO_INSTALLATION_KEY';

    public const STORAGE_PREFIX = 'gestion';

    public const MAX_LENGTH = 64;

    /** @var non-empty-string */
    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * Resolve Spatie backup.name from environment (safe during config load — env() only).
     */
    public static function resolveBackupNameFromEnvironment(): string
    {
        $key = self::readValidatedKeyFromEnv();

        if ($key !== null) {
            return self::backupNameForKey($key);
        }

        $appEnv = strtolower(trim((string) env('APP_ENV', 'production')));

        if (in_array($appEnv, ['local', 'testing'], true)) {
            return self::backupNameForKey('local-dev');
        }

        return self::STORAGE_PREFIX.'/__missing_installation_key__';
    }

    /**
     * @throws RuntimeException when backup operations must not run
     */
    public const MISSING_INSTALLATION_SUFFIX = '__missing_installation_key__';

    /**
     * Block destructive offsite cleanup unless the installation prefix is explicit and valid.
     *
     * Local cleanup is not gated by this method.
     *
     * @throws RuntimeException
     */
    public static function assertReadyForOffsiteCleanup(): void
    {
        $configured = (string) config('backup.backup.name', '');

        if ($configured === self::STORAGE_PREFIX.'/'.self::MISSING_INSTALLATION_SUFFIX) {
            throw new RuntimeException(
                'DATABASE SAFETY / BACKUP: offsite cleanup refused — '
                .self::ENV_VARIABLE.' is missing or invalid (unsafe backup prefix).',
            );
        }

        if (! self::isValidConfiguredBackupName($configured) && $configured !== self::backupNameForKey('local-dev')) {
            throw new RuntimeException(
                'DATABASE SAFETY / BACKUP: offsite cleanup refused — backup prefix is not a valid installation path.',
            );
        }

        if (self::shouldSkipStrictInstallationKeyCheck()) {
            return;
        }

        self::assertReadyForBackupOperation();
    }

    public static function assertReadyForBackupOperation(): void
    {
        if (self::shouldSkipStrictInstallationKeyCheck()) {
            return;
        }

        $key = self::readValidatedKeyFromEnv();

        if ($key === null) {
            throw new RuntimeException(
                'DATABASE SAFETY / BACKUP: '.self::ENV_VARIABLE.' is missing or invalid. '
                .'Set a lowercase installation key (a-z, 0-9, hyphen only) before running backups.',
            );
        }

        $expected = self::backupNameForKey($key);
        $configured = (string) config('backup.backup.name', '');

        if ($configured !== $expected) {
            throw new RuntimeException(
                'DATABASE SAFETY / BACKUP: backup name mismatch. Expected '.$expected.', got '.$configured.'.',
            );
        }
    }

    public static function isValidKeyFormat(?string $key): bool
    {
        if ($key === null || $key === '') {
            return false;
        }

        if (strlen($key) > self::MAX_LENGTH) {
            return false;
        }

        if (str_contains($key, '/') || str_contains($key, '\\') || str_contains($key, '.')) {
            return false;
        }

        if (str_contains($key, '..')) {
            return false;
        }

        return preg_match(self::PATTERN, $key) === 1;
    }

    /**
     * @return non-empty-string
     */
    public static function backupNameForKey(string $key): string
    {
        if (! self::isValidKeyFormat($key)) {
            throw new RuntimeException('Invalid installation key format.');
        }

        return self::STORAGE_PREFIX.'/'.$key;
    }

    /**
     * Whether config backup.name matches gestion/{valid-key}.
     */
    public static function isValidConfiguredBackupName(string $name): bool
    {
        if (! preg_match('#^gestion/([a-z0-9]+(?:-[a-z0-9]+)*)$#', $name, $matches)) {
            return false;
        }

        return self::isValidKeyFormat($matches[1]);
    }

    public static function configuredInstallationKey(): ?string
    {
        $name = (string) config('backup.backup.name', '');

        if (! preg_match('#^gestion/([a-z0-9]+(?:-[a-z0-9]+)*)$#', $name, $matches)) {
            return null;
        }

        $key = $matches[1];

        return self::isValidKeyFormat($key) ? $key : null;
    }

    private static function shouldSkipStrictInstallationKeyCheck(): bool
    {
        if (in_array(config('app.env'), ['local', 'testing'], true)) {
            return true;
        }

        return false;
    }

    private static function readValidatedKeyFromEnv(): ?string
    {
        $raw = env(self::ENV_VARIABLE);

        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);

        if ($trimmed === '') {
            return null;
        }

        if (! self::isValidKeyFormat($trimmed)) {
            return null;
        }

        return $trimmed;
    }
}
