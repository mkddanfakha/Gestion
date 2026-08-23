<?php

namespace App\Console\Commands;

use App\Auth\LegacyPermissionMigrator;
use App\Auth\RbacAuditService;
use Illuminate\Console\Command;

class MigrateLegacyPermissionsCommand extends Command
{
    protected $signature = 'rbac:migrate-legacy-permissions
                            {--dry-run : Afficher le plan sans modifier la base}
                            {--status : Afficher l’état legacy / canonique des pivots}
                            {--force : Exécuter sans confirmation interactive}';

    protected $description = 'Migre les pivots user_permissions *.edit / inventory.review vers *.update / inventory.reopen';

    public function handle(LegacyPermissionMigrator $migrator, RbacAuditService $rbacAudit): int
    {
        if ($this->option('status')) {
            return $this->printStatus($migrator);
        }

        $plan = $migrator->plan();
        $this->printPlan($plan, (bool) $this->option('dry-run'));

        if ($plan['nothing_to_do']) {
            $this->info('No legacy user permission assignments found.');
            $this->info('Nothing to migrate.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->comment('No database changes performed.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Appliquer la migration des pivots legacy ?', false)) {
            $this->warn('Migration annulée.');

            return self::SUCCESS;
        }

        $result = $migrator->migrate(dryRun: false);

        $rbacAudit->recordLegacyPermissionsMigrated(
            usersAffected: $result['users_affected'],
            canonicalAdded: $result['canonical_added'],
            legacyRemoved: $result['legacy_removed'],
            mappings: array_map(
                static fn (array $row): array => [
                    'legacy' => $row['legacy'],
                    'canonical' => $row['canonical'],
                    'legacy_to_remove' => $row['legacy_to_remove'],
                    'canonical_to_add' => $row['canonical_to_add'],
                ],
                $result['mappings'],
            ),
            viaConsole: true,
        );

        $this->newLine();
        $this->info('Migration completed.');
        $this->line("Users affected : {$result['users_affected']}");
        $this->line("Canonical pivots added : {$result['canonical_added']}");
        $this->line("Legacy pivots removed : {$result['legacy_removed']}");

        $status = $migrator->status();
        $this->line('Status : '.$status['status']);

        return $status['legacy_assignment_total'] === 0
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function printPlan(array $plan, bool $dryRun): void
    {
        $this->info($dryRun
            ? 'RBAC Legacy Permission Migration — DRY RUN'
            : 'RBAC Legacy Permission Migration — PLAN');
        $this->newLine();

        foreach ($plan['mappings'] as $row) {
            if ($row['legacy_to_remove'] === 0 && $row['canonical_to_add'] === 0) {
                continue;
            }

            $this->line("{$row['legacy']} → {$row['canonical']}");
            $this->line("  Legacy assignments : {$row['legacy_assignments']}");
            $this->line("  Canonical assignments : {$row['canonical_assignments']}");
            $this->line("  Canonical permissions to add : {$row['canonical_to_add']}");
            $this->line("  Legacy assignments to remove : {$row['legacy_to_remove']}");
            $this->newLine();
        }

        $this->line("Total users affected: {$plan['users_affected']}");
    }

    private function printStatus(LegacyPermissionMigrator $migrator): int
    {
        $status = $migrator->status();

        $this->info('=== RBAC LEGACY STATUS ===');
        $this->newLine();
        $this->line('Legacy permission definitions:');
        foreach ($status['legacy_definitions'] as $name => $present) {
            $this->line('  '.$name.': '.($present ? 'PRESENT' : 'MISSING'));
        }

        $this->newLine();
        $this->line('Legacy user assignments:');
        foreach ($status['legacy_assignments'] as $name => $count) {
            $this->line("  {$name}: {$count}");
        }

        $this->newLine();
        $this->line('Canonical user assignments:');
        foreach ($status['canonical_assignments'] as $name => $count) {
            $this->line("  {$name}: {$count}");
        }

        $this->newLine();
        $this->line('Status:');
        $this->line('  '.$status['status']);

        return self::SUCCESS;
    }
}
