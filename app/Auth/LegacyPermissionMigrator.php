<?php

namespace App\Auth;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Migration ciblée des pivots user_permissions legacy → canoniques.
 * Ne force-sync pas les RolePresets. Ne supprime pas les lignes permissions legacy.
 */
final class LegacyPermissionMigrator
{
    /**
     * @return array{mappings: list<array<string, mixed>>, users_affected: int, canonical_to_add: int, legacy_to_remove: int, nothing_to_do: bool}
     */
    public function plan(): array
    {
        $mappings = [];
        $userIds = [];
        $canonicalToAdd = 0;
        $legacyToRemove = 0;

        foreach (PermissionCatalog::legacyMappings() as $legacyName => $canonicalName) {
            $row = $this->analyzeMapping($legacyName, $canonicalName);
            $mappings[] = $row;
            $canonicalToAdd += $row['canonical_to_add'];
            $legacyToRemove += $row['legacy_to_remove'];
            foreach ($row['user_ids'] as $userId) {
                $userIds[$userId] = true;
            }
        }

        return [
            'mappings' => $mappings,
            'users_affected' => count($userIds),
            'canonical_to_add' => $canonicalToAdd,
            'legacy_to_remove' => $legacyToRemove,
            'nothing_to_do' => $legacyToRemove === 0,
        ];
    }

    /**
     * @param  (callable(string $legacy, string $canonical): void)|null  $afterAttachHook  Hook tests (peut throw pour rollback)
     * @return array{mappings: list<array<string, mixed>>, users_affected: int, canonical_added: int, legacy_removed: int, dry_run: bool}
     */
    public function migrate(bool $dryRun = false, ?callable $afterAttachHook = null): array
    {
        $plan = $this->plan();

        if ($dryRun || $plan['nothing_to_do']) {
            return [
                'mappings' => $plan['mappings'],
                'users_affected' => $plan['users_affected'],
                'canonical_added' => 0,
                'legacy_removed' => 0,
                'dry_run' => $dryRun || $plan['nothing_to_do'],
                'nothing_to_do' => $plan['nothing_to_do'],
            ];
        }

        $canonicalAdded = 0;
        $legacyRemoved = 0;
        $affectedUserIds = [];

        DB::transaction(function () use ($plan, $afterAttachHook, &$canonicalAdded, &$legacyRemoved, &$affectedUserIds): void {
            foreach ($plan['mappings'] as $row) {
                if ($row['legacy_to_remove'] === 0) {
                    continue;
                }

                if (! $row['legacy_id'] || ! $row['canonical_id']) {
                    throw new RuntimeException(
                        "Mapping incomplet : {$row['legacy']} → {$row['canonical']} (permissions manquantes en DB)."
                    );
                }

                $legacyId = (int) $row['legacy_id'];
                $canonicalId = (int) $row['canonical_id'];

                foreach ($row['user_ids'] as $userId) {
                    $userId = (int) $userId;
                    $affectedUserIds[$userId] = true;

                    $hasCanonical = DB::table('user_permissions')
                        ->where('user_id', $userId)
                        ->where('permission_id', $canonicalId)
                        ->exists();

                    if (! $hasCanonical) {
                        DB::table('user_permissions')->insert([
                            'user_id' => $userId,
                            'permission_id' => $canonicalId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $canonicalAdded++;
                    }

                    if ($afterAttachHook !== null) {
                        $afterAttachHook($row['legacy'], $row['canonical']);
                    }

                    $deleted = DB::table('user_permissions')
                        ->where('user_id', $userId)
                        ->where('permission_id', $legacyId)
                        ->delete();

                    $legacyRemoved += $deleted;
                }
            }
        });

        return [
            'mappings' => $plan['mappings'],
            'users_affected' => count($affectedUserIds),
            'canonical_added' => $canonicalAdded,
            'legacy_removed' => $legacyRemoved,
            'dry_run' => false,
            'nothing_to_do' => false,
            'affected_user_ids' => array_keys($affectedUserIds),
        ];
    }

    /**
     * @return array{
     *     legacy_definitions: array<string, bool>,
     *     canonical_definitions: array<string, bool>,
     *     legacy_assignments: array<string, int>,
     *     canonical_assignments: array<string, int>,
     *     legacy_assignment_total: int,
     *     status: string
     * }
     */
    public function status(): array
    {
        $legacyDefinitions = [];
        $canonicalDefinitions = [];
        $legacyAssignments = [];
        $canonicalAssignments = [];
        $total = 0;

        foreach (PermissionCatalog::legacyMappings() as $legacy => $canonical) {
            $legacyDefinitions[$legacy] = Permission::query()->where('name', $legacy)->exists();
            $canonicalDefinitions[$canonical] = Permission::query()->where('name', $canonical)->exists();
            $legacyCount = $this->pivotCount($legacy);
            $canonicalCount = $this->pivotCount($canonical);
            $legacyAssignments[$legacy] = $legacyCount;
            $canonicalAssignments[$canonical] = $canonicalCount;
            $total += $legacyCount;
        }

        return [
            'legacy_definitions' => $legacyDefinitions,
            'canonical_definitions' => $canonicalDefinitions,
            'legacy_assignments' => $legacyAssignments,
            'canonical_assignments' => $canonicalAssignments,
            'legacy_assignment_total' => $total,
            'status' => $total === 0 ? 'NO LEGACY USER ASSIGNMENTS' : 'LEGACY USER ASSIGNMENTS REMAIN',
        ];
    }

    /**
     * Snapshot des noms de permissions d'un utilisateur (ordre stable).
     *
     * @return list<string>
     */
    public function permissionNamesFor(User $user): array
    {
        $names = $user->permissions()->pluck('name')->all();
        sort($names);

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeMapping(string $legacyName, string $canonicalName): array
    {
        $legacy = Permission::query()->where('name', $legacyName)->first();
        $canonical = Permission::query()->where('name', $canonicalName)->first();

        $userIds = [];
        $usersAlreadyCanonical = 0;
        $canonicalToAdd = 0;

        if ($legacy) {
            $userIds = DB::table('user_permissions')
                ->where('permission_id', $legacy->id)
                ->pluck('user_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($canonical) {
                foreach ($userIds as $userId) {
                    $hasCanonical = DB::table('user_permissions')
                        ->where('user_id', $userId)
                        ->where('permission_id', $canonical->id)
                        ->exists();

                    if ($hasCanonical) {
                        $usersAlreadyCanonical++;
                    } else {
                        $canonicalToAdd++;
                    }
                }
            } else {
                $canonicalToAdd = count($userIds);
            }
        }

        return [
            'legacy' => $legacyName,
            'canonical' => $canonicalName,
            'legacy_present' => $legacy !== null,
            'canonical_present' => $canonical !== null,
            'legacy_id' => $legacy?->id,
            'canonical_id' => $canonical?->id,
            'legacy_assignments' => count($userIds),
            'canonical_assignments' => $canonical ? $this->pivotCount($canonicalName) : 0,
            'users_already_canonical' => $usersAlreadyCanonical,
            'canonical_to_add' => $canonicalToAdd,
            'legacy_to_remove' => count($userIds),
            'user_ids' => $userIds,
        ];
    }

    private function pivotCount(string $permissionName): int
    {
        $id = Permission::query()->where('name', $permissionName)->value('id');

        if (! $id) {
            return 0;
        }

        return (int) DB::table('user_permissions')->where('permission_id', $id)->count();
    }
}
