<?php

namespace App\Console\Commands;

use App\Auth\PermissionCatalogSynchronizer;
use Illuminate\Console\Command;
use Throwable;

class SyncPermissionCatalogCommand extends Command
{
    protected $signature = 'rbac:sync-permission-catalog
                            {--status : Afficher l’état catalogue ↔ permissions (lecture seule)}
                            {--dry-run : Simuler la synchronisation sans écrire}
                            {--confirm= : Phrase exacte « SYNC PERMISSION CATALOG ON <database> »}';

    protected $description = 'Synchronise le catalogue RBAC (PermissionCatalog) vers la table permissions';

    public function handle(PermissionCatalogSynchronizer $synchronizer): int
    {
        try {
            $context = $synchronizer->assertWritableContext();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->printContext($context);

        if ($this->option('status')) {
            return $this->printStatus($synchronizer->analyze());
        }

        if ($this->option('dry-run')) {
            return $this->printDryRun($synchronizer->analyze());
        }

        try {
            $synchronizer->assertConfirmationMatches(
                $this->option('confirm'),
                $context['database'],
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $result = $synchronizer->sync();
        } catch (Throwable $e) {
            $this->error('Échec de la synchronisation : '.$e->getMessage());
            $this->error('Aucune synchronisation partielle n’a été conservée (transaction).');

            return self::FAILURE;
        }

        return $this->printSyncResult($context, $result);
    }

    /**
     * @param  array{environment: string, connection: string, database: string}  $context
     */
    private function printContext(array $context): void
    {
        $this->line('Environment : '.$context['environment']);
        $this->line('Connection  : '.$context['connection']);
        $this->line('Database    : '.$context['database']);
        $this->newLine();
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function printStatus(array $analysis): int
    {
        $this->info('RBAC PERMISSION CATALOG STATUS');
        $this->newLine();
        $this->line('Catalog permissions : '.$analysis['catalog_count']);
        $this->line('Existing permissions: '.$analysis['existing_count']);
        $this->line('Missing permissions  : '.$analysis['missing_count']);
        $this->line('Orphan permissions  : '.$analysis['orphan_count']);

        if ($analysis['missing_names'] !== []) {
            $this->newLine();
            $this->line('Missing permissions:');
            foreach ($analysis['missing_names'] as $name) {
                $this->line('- '.$name);
            }
        }

        if ($analysis['orphan_names'] !== []) {
            $this->newLine();
            $this->line('Orphan permissions:');
            foreach ($analysis['orphan_names'] as $name) {
                $this->line('- '.$name);
            }
            $this->comment('Orphan permissions are listed only — they are never deleted.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function printDryRun(array $analysis): int
    {
        $this->info('RBAC PERMISSION CATALOG DRY RUN');
        $this->newLine();
        $this->line('Catalog permissions : '.$analysis['catalog_count']);
        $this->line('Existing permissions: '.$analysis['existing_count']);
        $this->line('Missing permissions : '.$analysis['missing_count']);
        $this->line('Orphan permissions  : '.$analysis['orphan_count']);

        if ($analysis['missing_names'] !== []) {
            $this->newLine();
            $this->line('Would create:');
            foreach ($analysis['missing_names'] as $name) {
                $this->line('- '.$name);
            }
        }

        $this->newLine();
        $this->comment('No changes performed.');

        return self::SUCCESS;
    }

    /**
     * @param  array{environment: string, connection: string, database: string}  $context
     * @param  array{before: array<string, mixed>, after: array<string, mixed>, created: int, created_names: list<string>}  $result
     */
    private function printSyncResult(array $context, array $result): int
    {
        $this->info('RBAC PERMISSION CATALOG SYNC');
        $this->newLine();
        $this->line('Environment : '.$context['environment']);
        $this->line('Database    : '.$context['database']);
        $this->newLine();
        $this->line('Catalog permissions : '.$result['after']['catalog_count']);
        $this->line('Existing before     : '.$result['before']['existing_count']);
        $this->line('Created             : '.$result['created']);
        $this->line('Existing after      : '.$result['after']['existing_count']);
        $this->newLine();
        $this->line('User permissions    : unchanged');
        $this->newLine();
        $this->info('SYNC COMPLETED');

        return self::SUCCESS;
    }
}
