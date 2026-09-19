<?php

namespace App\Auth;

use App\Database\DatabaseSafetyGuard;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Synchronise PermissionCatalog → table permissions (définitions uniquement).
 *
 * Ne touche jamais user_permissions, rôles, RolePresets ni l’autorisation runtime.
 */
final class PermissionCatalogSynchronizer
{
    public static function confirmationPhraseForDatabase(string $database): string
    {
        return 'SYNC PERMISSION CATALOG ON '.$database;
    }

    /**
     * @return array{environment: string, connection: string, database: string}
     */
    public function context(?string $connection = null): array
    {
        $connection ??= (string) config('database.default', 'sqlite');
        $database = DatabaseSafetyGuard::resolveDatabaseName($connection);

        return [
            'environment' => (string) config('app.env', 'unknown'),
            'connection' => $connection,
            'database' => $database,
        ];
    }

    /**
     * @return array{
     *     catalog_count: int,
     *     existing_count: int,
     *     missing_count: int,
     *     orphan_count: int,
     *     existing_names: list<string>,
     *     missing_names: list<string>,
     *     orphan_names: list<string>
     * }
     */
    public function analyze(): array
    {
        $catalog = PermissionCatalog::all();
        $dbRows = Permission::query()
            ->orderBy('name')
            ->get(['id', 'name', 'resource', 'action', 'description']);

        $byResourceAction = [];
        foreach ($dbRows as $row) {
            $byResourceAction[$this->resourceActionKey((string) $row->resource, (string) $row->action)] = $row;
        }

        $catalogNames = [];
        $existingNames = [];
        $missingNames = [];

        foreach ($catalog as $definition) {
            $name = $definition['name'];
            $catalogNames[$name] = true;
            $key = $this->resourceActionKey($definition['module'], $definition['action']);

            if (isset($byResourceAction[$key])) {
                $existingNames[] = $name;
            } else {
                $missingNames[] = $name;
            }
        }

        $orphanNames = [];
        foreach ($dbRows as $row) {
            $name = (string) $row->name;
            if (! isset($catalogNames[$name])) {
                $orphanNames[] = $name;
            }
        }

        sort($existingNames);
        sort($missingNames);
        sort($orphanNames);

        return [
            'catalog_count' => count($catalog),
            'existing_count' => count($existingNames),
            'missing_count' => count($missingNames),
            'orphan_count' => count($orphanNames),
            'existing_names' => $existingNames,
            'missing_names' => $missingNames,
            'orphan_names' => $orphanNames,
        ];
    }

    /**
     * Crée uniquement les permissions catalogue absentes (firstOrCreate).
     *
     * @return array{
     *     before: array<string, mixed>,
     *     after: array<string, mixed>,
     *     created: int,
     *     created_names: list<string>
     * }
     */
    public function sync(): array
    {
        $before = $this->analyze();
        $createdNames = [];

        DB::transaction(function () use (&$createdNames): void {
            foreach (PermissionCatalog::all() as $definition) {
                $permission = Permission::firstOrCreate(
                    [
                        'resource' => $definition['module'],
                        'action' => $definition['action'],
                    ],
                    [
                        'name' => $definition['name'],
                        'description' => $definition['description'],
                    ],
                );

                if ($permission->wasRecentlyCreated) {
                    $createdNames[] = $definition['name'];
                }
            }
        });

        sort($createdNames);
        $after = $this->analyze();

        return [
            'before' => $before,
            'after' => $after,
            'created' => count($createdNames),
            'created_names' => $createdNames,
        ];
    }

    /**
     * Alias sémantique de analyze() pour les prévisualisations.
     *
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        return $this->analyze();
    }

    public function assertWritableContext(?string $connection = null): array
    {
        $context = $this->context($connection);

        if ($context['database'] === '') {
            throw new RuntimeException(
                "Impossible de déterminer le nom de la base pour la connexion « {$context['connection']} ». Aucune écriture effectuée."
            );
        }

        return $context;
    }

    public function assertConfirmationMatches(?string $provided, string $database): void
    {
        $expected = self::confirmationPhraseForDatabase($database);

        if (! is_string($provided) || $provided !== $expected) {
            throw new RuntimeException(implode("\n", [
                'RBAC PERMISSION CATALOG SYNC REFUSED',
                '',
                'Confirmation manquante ou incorrecte.',
                "Attendu exactement : {$expected}",
                '',
                'Aucune écriture effectuée.',
                '--force ne contourne pas cette protection.',
            ]));
        }
    }

    private function resourceActionKey(string $resource, string $action): string
    {
        return $resource.'|'.$action;
    }
}
