<?php

namespace App\Database;

use RuntimeException;

/**
 * Maps logical restore allow-list names to physical MySQL database names (hosting prefixes).
 * Fail-closed: no implicit fallback to the logical name when mapping is required.
 *
 * DB_RESTORE_DATABASE_MAP format (only):
 *   logical:physical,logical:physical
 * Example:
 *   gestion_recovery:damo5182_gestion_recovery,gestion_test:damo5182_gestion_test
 */
final class RestoreDatabaseTargetResolver
{
    /**
     * Load restore map for Laravel config bootstrap without aborting application startup.
     *
     * @return array{map: array<string, string>, error: ?string}
     */
    public static function bootstrapFromEnv(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return ['map' => [], 'error' => null];
        }

        try {
            return [
                'map' => self::parseEnvMap($raw),
                'error' => null,
            ];
        } catch (RuntimeException $exception) {
            return [
                'map' => [],
                'error' => $exception->getMessage(),
            ];
        }
    }

    public static function configurationError(): ?string
    {
        $error = config('database-safety.restore_database_map_configuration_error');

        return is_string($error) && $error !== '' ? $error : null;
    }

    /**
     * @throws RuntimeException
     */
    public static function assertRestoreMappingConfigurationReady(): void
    {
        $error = self::configurationError();

        if ($error !== null) {
            throw new RuntimeException($error);
        }
    }

    /**
     * @return array<string, string> logical => physical
     */
    public static function configuredMap(): array
    {
        $configured = config('database-safety.restore_database_map', []);

        if (! is_array($configured)) {
            return [];
        }

        $map = [];

        foreach ($configured as $logical => $physical) {
            if (! is_string($logical) || ! is_string($physical)) {
                continue;
            }

            $logical = trim($logical);
            $physical = trim($physical);

            if ($logical === '' || $physical === '') {
                continue;
            }

            $map[$logical] = $physical;
        }

        return $map;
    }

    /**
     * @throws RuntimeException
     */
    public function resolvePhysicalName(string $logicalTarget): string
    {
        $logical = DatabaseSafetyGuard::normalizeExplicitTarget($logicalTarget);

        if ($logical === '') {
            throw new RuntimeException('DATABASE SAFETY: restore target mapping requires a non-empty logical database name.');
        }

        DatabaseSafetyGuard::assertExplicitRestoreTarget($logical);

        self::assertRestoreMappingConfigurationReady();

        $map = self::configuredMap();

        if ($map === []) {
            throw new RuntimeException(
                'DATABASE SAFETY: DB_RESTORE_DATABASE_MAP is missing — cannot resolve physical name for '.$logical.'.',
            );
        }

        self::assertMapIntegrity($map);

        if (! array_key_exists($logical, $map)) {
            throw new RuntimeException(
                'DATABASE SAFETY: no physical database mapping for restore target '.$logical.'.',
            );
        }

        $physical = trim($map[$logical]);

        if ($physical === '') {
            throw new RuntimeException(
                'DATABASE SAFETY: empty physical database mapping for restore target '.$logical.'.',
            );
        }

        self::assertPhysicalTargetAllowed($logical, $physical);

        return $physical;
    }

    /**
     * @param  array<string, string>  $map
     *
     * @throws RuntimeException
     */
    public static function assertMapIntegrity(array $map): void
    {
        if ($map === []) {
            throw new RuntimeException('DATABASE SAFETY: DB_RESTORE_DATABASE_MAP is empty.');
        }

        $physicalSeen = [];
        $activePhysical = DatabaseSafetyGuard::resolveDatabaseName('mysql');

        foreach ($map as $logical => $physical) {
            $logical = trim($logical);
            $physical = trim($physical);

            if ($logical === '' || $physical === '') {
                throw new RuntimeException('DATABASE SAFETY: restore database map contains empty logical or physical names.');
            }

            if (DatabaseSafetyGuard::isProtectedDatabase($logical)) {
                throw new RuntimeException(
                    'DATABASE SAFETY: restore database map must not include protected logical name '.$logical.'.',
                );
            }

            if (! DatabaseSafetyGuard::isRestoreAllowedDatabase($logical)) {
                throw new RuntimeException(
                    'DATABASE SAFETY: restore database map logical name '.$logical.' is not in the restore allow-list.',
                );
            }

            if (! DatabaseSafetyGuard::isSafeDatabaseIdentifier($physical)) {
                throw new RuntimeException(
                    'DATABASE SAFETY: restore database map physical name for '.$logical.' is invalid.',
                );
            }

            self::assertPhysicalTargetAllowed($logical, $physical, $activePhysical);

            $logicalKey = strtolower($logical);

            if (isset($physicalSeen[$logicalKey])) {
                throw new RuntimeException(
                    'DATABASE SAFETY: duplicate logical restore target '.$logical.' in DB_RESTORE_DATABASE_MAP.',
                );
            }

            $physicalKey = strtolower($physical);

            if (isset($physicalSeen['physical:'.$physicalKey])) {
                throw new RuntimeException(
                    'DATABASE SAFETY: duplicate physical database '.$physical.' mapped from multiple logical restore targets.',
                );
            }

            $physicalSeen[$logicalKey] = $logical;
            $physicalSeen['physical:'.$physicalKey] = $logical;
        }
    }

    /**
     * @throws RuntimeException
     */
    public static function assertPhysicalTargetAllowed(
        string $logical,
        string $physical,
        ?string $activePhysical = null,
    ): void {
        if (! DatabaseSafetyGuard::isSafeDatabaseIdentifier($physical)) {
            throw new RuntimeException(
                'DATABASE SAFETY: invalid physical database identifier for restore target '.$logical.'.',
            );
        }

        if (DatabaseSafetyGuard::isProtectedDatabase($physical)) {
            throw new RuntimeException(
                'DATABASE SAFETY: physical mapping for '.$logical.' references a protected database name.',
            );
        }

        $activePhysical ??= DatabaseSafetyGuard::resolveDatabaseName('mysql');

        if ($activePhysical !== '' && strcasecmp($physical, $activePhysical) === 0) {
            throw new RuntimeException(
                'DATABASE SAFETY: physical mapping for '.$logical.' must not target the active application database.',
            );
        }
    }

    /**
     * Parse DB_RESTORE_DATABASE_MAP using colon-separated logical:physical pairs only.
     *
     * @return array<string, string>
     *
     * @throws RuntimeException when syntax is invalid
     */
    public static function parseEnvMap(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $map = [];

        foreach (array_filter(array_map('trim', explode(',', $raw))) as $pair) {
            if (str_contains($pair, '=')) {
                throw new RuntimeException(
                    'DATABASE SAFETY: DB_RESTORE_DATABASE_MAP must use logical:physical pairs (colon separator). Equals sign is not allowed.',
                );
            }

            if (! str_contains($pair, ':')) {
                throw new RuntimeException(
                    'DATABASE SAFETY: invalid DB_RESTORE_DATABASE_MAP entry (expected logical:physical): '.$pair,
                );
            }

            [$logical, $physical] = explode(':', $pair, 2);
            $logical = trim($logical);
            $physical = trim($physical);

            if ($logical === '' || $physical === '') {
                throw new RuntimeException(
                    'DATABASE SAFETY: incomplete DB_RESTORE_DATABASE_MAP pair (expected logical:physical): '.$pair,
                );
            }

            if (isset($map[$logical])) {
                throw new RuntimeException(
                    'DATABASE SAFETY: duplicate logical restore target '.$logical.' in DB_RESTORE_DATABASE_MAP.',
                );
            }

            $map[$logical] = $physical;
        }

        return $map;
    }
}
