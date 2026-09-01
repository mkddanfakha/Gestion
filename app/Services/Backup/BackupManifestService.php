<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Official server-side backup manifest + integrity verification.
 * Sidecar path remains meta/{zip}.json via BackupMetadataService.
 */
final class BackupManifestService
{
    public function calculateHash(string $absoluteZipPath): string
    {
        if (! is_file($absoluteZipPath)) {
            throw new RuntimeException('Backup archive missing for hash calculation.');
        }

        $hash = hash_file('sha256', $absoluteZipPath);
        if (! is_string($hash) || $hash === '') {
            throw new RuntimeException('Unable to calculate archive SHA-256.');
        }

        return strtolower($hash);
    }

    /**
     * Build + write official server manifest after the final ZIP is on disk.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function createAndWriteForExistingZip(string $zipFileName, array $attributes = []): array
    {
        $absolute = BackupPathGuard::resolveExistingBackupPath($zipFileName);
        $filename = basename($absolute);

        if (! is_file($absolute)) {
            throw new RuntimeException('Backup archive not found.');
        }

        $size = (int) (filesize($absolute) ?: 0);
        $sha256 = $this->calculateHash($absolute);

        $manifest = BackupManifest::build(array_merge($attributes, [
            'filename' => $filename,
            'sha256' => $sha256,
            'size_bytes' => $size,
        ]));

        BackupManifest::assertNoSecrets($manifest);
        $this->assertMinimalStructure($manifest);

        $path = BackupMetadataService::resolveMetaPathForZip($filename);
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException('Unable to write backup manifest sidecar.');
        }

        return $manifest;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $zipFileName): ?array
    {
        $raw = BackupMetadataService::readForZip($zipFileName);
        if ($raw === null) {
            return null;
        }

        try {
            return BackupManifest::normalizeLegacy($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function validate(array $manifest): bool
    {
        try {
            $this->assertMinimalStructure($manifest);
            BackupManifest::assertNoSecrets($manifest);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{
     *   result: string,
     *   integrity: string,
     *   compatibility: string,
     *   filename: string,
     *   sha256_expected: ?string,
     *   sha256_actual: ?string,
     *   size_bytes: ?int,
     *   manifest_version: ?int,
     *   message: string,
     *   manifest: ?array
     * }
     */
    public function verifyIntegrity(string $zipFileName): array
    {
        $safeName = BackupPathGuard::sanitizeBackupFileName($zipFileName);

        try {
            $absolute = BackupPathGuard::resolveExistingBackupPath($safeName);
        } catch (RuntimeException) {
            return $this->integrityResult(
                BackupManifest::INTEGRITY_MISSING,
                $safeName,
                null,
                null,
                null,
                null,
                null,
                'Fichier de sauvegarde introuvable.',
            );
        }

        if (! is_file($absolute)) {
            return $this->integrityResult(
                BackupManifest::INTEGRITY_MISSING,
                $safeName,
                null,
                null,
                null,
                null,
                null,
                'Fichier de sauvegarde introuvable.',
            );
        }

        $size = (int) (filesize($absolute) ?: 0);
        $actualHash = $this->calculateHash($absolute);
        $manifest = $this->read($safeName);

        if ($manifest === null) {
            return $this->integrityResult(
                BackupManifest::INTEGRITY_MANIFEST_INVALID,
                $safeName,
                null,
                $actualHash,
                $size,
                null,
                null,
                'Manifeste absent ou illisible. L\'intégrité ne peut pas être confirmée.',
                BackupManifest::COMPAT_REVIEW,
            );
        }

        if (! $this->validate($manifest)) {
            return $this->integrityResult(
                BackupManifest::INTEGRITY_MANIFEST_INVALID,
                $safeName,
                null,
                $actualHash,
                $size,
                isset($manifest['manifest_version']) ? (int) $manifest['manifest_version'] : null,
                $manifest,
                'Manifeste invalide.',
                BackupManifest::COMPAT_REVIEW,
            );
        }

        $expected = isset($manifest['archive']['sha256']) && is_string($manifest['archive']['sha256'])
            ? strtolower($manifest['archive']['sha256'])
            : (isset($manifest['sha256']) && is_string($manifest['sha256']) ? strtolower($manifest['sha256']) : null);

        if ($expected === null || $expected === '') {
            return $this->integrityResult(
                BackupManifest::INTEGRITY_MANIFEST_INVALID,
                $safeName,
                null,
                $actualHash,
                $size,
                (int) ($manifest['manifest_version'] ?? BackupManifest::VERSION),
                $manifest,
                'Le manifeste ne contient pas de SHA-256.',
                BackupManifest::COMPAT_REVIEW,
            );
        }

        if (! hash_equals($expected, $actualHash)) {
            return $this->integrityResult(
                BackupManifest::INTEGRITY_INVALID,
                $safeName,
                $expected,
                $actualHash,
                $size,
                (int) ($manifest['manifest_version'] ?? BackupManifest::VERSION),
                $manifest,
                'Le hash SHA-256 ne correspond pas au fichier ZIP.',
                $this->compatibilityFor($manifest),
            );
        }

        $expectedSize = $manifest['archive']['size_bytes'] ?? $manifest['size_bytes'] ?? null;
        if ($expectedSize !== null && (int) $expectedSize !== $size) {
            return $this->integrityResult(
                BackupManifest::INTEGRITY_INVALID,
                $safeName,
                $expected,
                $actualHash,
                $size,
                (int) ($manifest['manifest_version'] ?? BackupManifest::VERSION),
                $manifest,
                'La taille du fichier ne correspond pas au manifeste.',
                $this->compatibilityFor($manifest),
            );
        }

        return $this->integrityResult(
            BackupManifest::INTEGRITY_VALID,
            $safeName,
            $expected,
            $actualHash,
            $size,
            (int) ($manifest['manifest_version'] ?? BackupManifest::VERSION),
            $manifest,
            'Intégrité vérifiée : manifeste et SHA-256 cohérents.',
            $this->compatibilityFor($manifest),
        );
    }

    /**
     * Lightweight listing hint without full re-hash (optional expensive verify is separate).
     *
     * @return array{integrity: string, compatibility: string, sha256: ?string, manifest_version: ?int}
     */
    public function listingIntegrityHint(string $zipFileName, ?array $meta = null): array
    {
        $raw = $meta ?? BackupMetadataService::readForZip($zipFileName);
        if ($raw === null) {
            return [
                'integrity' => 'unknown',
                'compatibility' => BackupManifest::COMPAT_REVIEW,
                'sha256' => null,
                'manifest_version' => null,
            ];
        }

        $wasLegacy = ! isset($raw['manifest_version']);

        try {
            $manifest = BackupManifest::normalizeLegacy($raw);
        } catch (\Throwable) {
            return [
                'integrity' => 'manifest_invalid',
                'compatibility' => BackupManifest::COMPAT_REVIEW,
                'sha256' => null,
                'manifest_version' => null,
            ];
        }

        if (! $this->validate($manifest)) {
            return [
                'integrity' => 'manifest_invalid',
                'compatibility' => BackupManifest::COMPAT_REVIEW,
                'sha256' => null,
                'manifest_version' => isset($manifest['manifest_version']) ? (int) $manifest['manifest_version'] : null,
            ];
        }

        $sha = $manifest['archive']['sha256'] ?? $manifest['sha256'] ?? null;

        return [
            'integrity' => is_string($sha) && $sha !== '' ? 'manifest_present' : 'manifest_invalid',
            'compatibility' => $wasLegacy
                ? BackupManifest::COMPAT_REVIEW
                : $this->compatibilityFor($manifest),
            'sha256' => is_string($sha) ? $sha : null,
            'manifest_version' => $wasLegacy
                ? null
                : (int) ($manifest['manifest_version'] ?? BackupManifest::VERSION),
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function compatibilityFor(array $manifest): string
    {
        $version = (int) ($manifest['manifest_version'] ?? 0);
        if ($version < 1) {
            // Legacy sidecar without manifest_version field after normalize still gets VERSION=1
            return BackupManifest::COMPAT_REVIEW;
        }

        if ($version > BackupManifest::VERSION) {
            return BackupManifest::COMPAT_INCOMPATIBLE;
        }

        $dbIncluded = (bool) ($manifest['database']['included'] ?? true);
        if (! $dbIncluded) {
            return BackupManifest::COMPAT_INCOMPATIBLE;
        }

        $status = strtolower((string) ($manifest['status'] ?? ''));
        if (in_array($status, [
            BackupMetadataService::STATUS_CORRUPTED,
            BackupMetadataService::STATUS_FAILED,
            BackupMetadataService::STATUS_INCOMPATIBLE,
        ], true)) {
            return BackupManifest::COMPAT_INCOMPATIBLE;
        }

        if (! isset($manifest['manifest_version']) && isset($manifest['backup_format_version'])) {
            return BackupManifest::COMPAT_REVIEW;
        }

        return BackupManifest::COMPAT_COMPATIBLE;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function assertMinimalStructure(array $manifest): void
    {
        foreach (['manifest_version', 'backup_id', 'type', 'source', 'status', 'created_at', 'archive'] as $key) {
            if (! array_key_exists($key, $manifest)) {
                throw new RuntimeException('Manifest missing key: '.$key);
            }
        }

        if ((int) $manifest['manifest_version'] < 1) {
            throw new RuntimeException('Invalid manifest_version.');
        }

        if (! is_array($manifest['archive'])) {
            throw new RuntimeException('Manifest archive section invalid.');
        }

        $sha = $manifest['archive']['sha256'] ?? null;
        if (! is_string($sha) || ! preg_match('/^[a-f0-9]{64}$/', strtolower($sha))) {
            throw new RuntimeException('Manifest SHA-256 invalid.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $manifest
     * @return array{
     *   result: string,
     *   integrity: string,
     *   compatibility: string,
     *   filename: string,
     *   sha256_expected: ?string,
     *   sha256_actual: ?string,
     *   size_bytes: ?int,
     *   manifest_version: ?int,
     *   message: string,
     *   manifest: ?array
     * }
     */
    private function integrityResult(
        string $result,
        string $filename,
        ?string $expected,
        ?string $actual,
        ?int $size,
        ?int $manifestVersion,
        ?array $manifest,
        string $message,
        ?string $compatibility = null,
    ): array {
        return [
            'result' => $result,
            'integrity' => $result,
            'compatibility' => $compatibility ?? (
                $manifest ? $this->compatibilityFor($manifest) : BackupManifest::COMPAT_REVIEW
            ),
            'filename' => $filename,
            'sha256_expected' => $expected,
            'sha256_actual' => $actual,
            'size_bytes' => $size,
            'manifest_version' => $manifestVersion,
            'message' => $message,
            'manifest' => $manifest,
        ];
    }
}
