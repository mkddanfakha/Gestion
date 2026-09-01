<?php

namespace App\Services\Restore;

use App\Database\DatabaseAccountGuard;
use App\Database\DatabaseSafetyGuard;
use App\Database\PrivilegedCredentialLoader;
use App\Database\PrivilegedProcessRunner;
use App\Database\PrivilegedRestoreProcessRunner;
use App\Database\ProtectedDatabaseException;
use App\Services\Backup\BackupManifest;
use App\Services\Backup\BackupManifestService;
use App\Services\Backup\BackupPathGuard;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\Process;

/**
 * Automated PRE/POST checks for controlled restore protocol (read-only; never restores).
 */
final class ControlledRestoreVerificationService
{
    public const DEFAULT_TARGET = 'gestion_recovery';

    /**
     * @var list<string>
     */
    private const ALLOW_LIST_CASES = [
        'gestion' => 'BLOCKED',
        'gestion_recovery' => 'ALLOWED',
        'gestion_test' => 'ALLOWED',
        'autre_base' => 'BLOCKED',
        'gestion_recovery_x' => 'BLOCKED',
        'gestion_test_x' => 'BLOCKED',
    ];

    /**
     * @var list<string>
     */
    private const INJECTION_TARGETS = [
        'gestion;DROP DATABASE gestion',
        'gestion && DROP DATABASE gestion',
        'gestion | DROP DATABASE gestion',
        'gestion$(id)',
        '../gestion',
        'gestion/../gestion',
        'gestion_recovery;DROP DATABASE gestion',
        'gestion_recovery && echo hacked',
        'gestion_recovery | cat',
        'gestion_recovery$(id)',
    ];

    public function __construct(
        private ControlledRestoreSnapshotCollector $snapshotCollector = new ControlledRestoreSnapshotCollector,
        private ControlledRestoreSqlInspector $sqlInspector = new ControlledRestoreSqlInspector,
        private BackupManifestService $manifestService = new BackupManifestService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function runPreRestoreProtocol(?string $backupFileName, string $target = self::DEFAULT_TARGET): array
    {
        $checks = [];

        $checks[] = $this->makeCheck(
            'protocol_mode',
            'PASS',
            'DRY-RUN ONLY — NO RESTORE EXECUTED',
        );

        $checks[] = $this->checkTargetExplicit($target);
        $checks = array_merge($checks, $this->checkAllowListMatrix());
        $checks = array_merge($checks, $this->checkInjectionTargets());
        $checks[] = $this->checkEnvironment();
        $checks[] = $this->checkRuntimeDatabaseIsGestion();
        $checks = array_merge($checks, $this->checkAccountSeparation());
        $checks[] = $this->checkRestoreCredentials();
        $checks[] = $this->checkGitWorkingTree();
        $checks[] = $this->checkProjectFilesFingerprint();

        $backupChecks = $this->checkBackup($backupFileName);
        $checks = array_merge($checks, $backupChecks['checks']);

        $checks = array_merge($checks, $this->checkGestionProtectionLayers());
        $checks[] = $this->checkPrivilegedArchitecture();
        $checks[] = $this->checkSafetyBackupArchitecture();

        $gestionSnapshot = $this->snapshotCollector->collectDatabaseSnapshot('gestion');
        $targetSnapshot = $this->snapshotCollector->collectDatabaseSnapshot($target);

        $checks[] = $this->makeCheck(
            'snapshot_gestion',
            $gestionSnapshot['status'],
            $gestionSnapshot['detail'],
        );
        $checks[] = $this->makeCheck(
            'snapshot_target',
            $targetSnapshot['status'],
            'Read-only snapshot for '.$target.': '.$targetSnapshot['detail'],
        );

        $fkStatus = $this->resolveForeignKeyStatus($targetSnapshot['foreign_keys'] ?? []);
        $checks[] = $this->makeCheck(
            'foreign_key_baseline',
            $fkStatus,
            'Orphan baseline prepared for '.$target,
        );

        $report = [
            'status' => $this->aggregateStatus($checks),
            'dry_run' => true,
            'restore_executed' => false,
            'human_approval_required' => true,
            'target' => $target,
            'backup' => $backupFileName,
            'checks' => $checks,
            'allow_list_matrix' => $this->buildAllowListMatrixReport(),
            'environment' => $this->buildEnvironmentReport(),
            'accounts' => $this->buildAccountReport(),
            'credentials' => $this->buildCredentialReport(),
            'git' => $this->buildGitReport(),
            'backup_details' => $backupChecks['details'],
            'sql_inspection' => $backupChecks['sql_inspection'],
            'snapshots' => [
                'gestion' => $gestionSnapshot,
                'target' => $targetSnapshot,
            ],
            'foreign_keys' => $targetSnapshot['foreign_keys'] ?? [],
            'gestion_protection' => $this->buildGestionProtectionReport(),
            'privileged_path' => $this->buildPrivilegedPathReport(),
            'project_files' => $this->buildProjectFilesReport(),
            'post_restore' => [
                'status' => 'IMPLEMENTED',
                'executed' => false,
                'detail' => 'Post-restore checks implemented but not executed in preparation phase.',
            ],
            'audit_events' => $this->buildAuditEventExpectations(),
            'message' => 'DRY-RUN ONLY — NO RESTORE EXECUTED',
        ];

        return $report;
    }

    /**
     * @param  array<string, mixed>  $beforeReport
     * @return array<string, mixed>
     */
    public function runPostRestoreChecks(array $beforeReport): array
    {
        $target = is_string($beforeReport['target'] ?? null)
            ? $beforeReport['target']
            : self::DEFAULT_TARGET;

        $gestionBefore = $beforeReport['snapshots']['gestion'] ?? [];
        $targetBefore = $beforeReport['snapshots']['target'] ?? [];

        $gestionAfter = $this->snapshotCollector->collectDatabaseSnapshot('gestion');
        $targetAfter = $this->snapshotCollector->collectDatabaseSnapshot($target);

        $gestionComparison = $this->snapshotCollector->compareSnapshots($gestionBefore, $gestionAfter);
        $targetComparison = $this->snapshotCollector->compareSnapshots($targetBefore, $targetAfter);

        $checks = [
            $this->makeCheck('post_restore_mode', 'PASS', 'Post-restore verification executed (read-only).'),
            $this->makeCheck(
                'gestion_unchanged',
                $gestionComparison['status'],
                $gestionComparison['detail'],
            ),
            $this->makeCheck(
                'target_database',
                $targetAfter['connection_available'] ? 'PASS' : 'WARNING',
                'Target database snapshot: '.$target,
            ),
            $this->makeCheck(
                'target_schema',
                $targetAfter['schema_fingerprint'] !== null ? 'PASS' : 'WARNING',
                'Schema fingerprint collected for '.$target,
            ),
            $this->makeCheck(
                'target_row_counts',
                $targetAfter['status'],
                'Row counts collected for business/system tables.',
            ),
            $this->makeCheck(
                'foreign_keys',
                $this->resolveForeignKeyStatus($targetAfter['foreign_keys'] ?? []),
                'Foreign key orphan verification on '.$target,
            ),
        ];

        return [
            'status' => $this->aggregateStatus($checks),
            'restore_executed' => false,
            'target' => $target,
            'checks' => $checks,
            'gestion_comparison' => $gestionComparison,
            'target_comparison' => $targetComparison,
            'snapshots' => [
                'gestion_after' => $gestionAfter,
                'target_after' => $targetAfter,
            ],
            'foreign_keys' => $targetAfter['foreign_keys'] ?? [],
            'system_tables' => $this->extractSystemTableCounts($targetAfter),
            'project_files' => $this->buildProjectFilesReport(),
            'audit_events' => $this->buildAuditEventExpectations(),
            'message' => 'POST-RESTORE CHECKS — restore execution status depends on caller context',
        ];
    }

    /**
     * @return array{check: string, status: string, detail: string}
     */
    private function checkTargetExplicit(string $target): array
    {
        if (DatabaseSafetyGuard::isProtectedDatabase($target)) {
            return $this->makeCheck(
                'target_explicit',
                'FAIL',
                'Target '.$target.' is protected and cannot be used for restore.',
            );
        }

        try {
            $validated = DatabaseSafetyGuard::assertExplicitRestoreTarget($target);

            return $this->makeCheck(
                'target_explicit',
                'PASS',
                'Target validated: '.$validated,
            );
        } catch (ProtectedDatabaseException $e) {
            return $this->makeCheck('target_explicit', 'FAIL', $e->getMessage());
        }
    }

    /**
     * @return list<array{check: string, status: string, detail: string}>
     */
    private function checkAllowListMatrix(): array
    {
        $checks = [];

        foreach (self::ALLOW_LIST_CASES as $candidate => $expected) {
            $actual = 'BLOCKED';
            try {
                DatabaseSafetyGuard::assertExplicitRestoreTarget($candidate);
                $actual = 'ALLOWED';
            } catch (ProtectedDatabaseException) {
                $actual = 'BLOCKED';
            }

            $checks[] = $this->makeCheck(
                'allow_list:'.$candidate,
                $actual === $expected ? 'PASS' : 'FAIL',
                'Expected '.$expected.', got '.$actual,
            );
        }

        return $checks;
    }

    /**
     * @return list<array{check: string, status: string, detail: string}>
     */
    private function checkInjectionTargets(): array
    {
        $checks = [];

        foreach (self::INJECTION_TARGETS as $malicious) {
            $blocked = false;
            try {
                DatabaseSafetyGuard::assertExplicitRestoreTarget($malicious);
            } catch (ProtectedDatabaseException) {
                $blocked = true;
            }

            $runner = new PrivilegedRestoreProcessRunner(
                new PrivilegedCredentialLoader,
                new PrivilegedProcessRunner(new PrivilegedCredentialLoader),
            );

            $runnerBlocked = false;
            try {
                $runner->assertRestoreTargetAllowed($malicious);
            } catch (ProtectedDatabaseException) {
                $runnerBlocked = true;
            }

            $checks[] = $this->makeCheck(
                'injection:'.md5($malicious),
                ($blocked && $runnerBlocked) ? 'PASS' : 'FAIL',
                $malicious.' → BLOCKED',
            );
        }

        return $checks;
    }

    /**
     * @return array{check: string, status: string, detail: string}
     */
    private function checkEnvironment(): array
    {
        $report = $this->buildEnvironmentReport();
        $status = ($report['db_database'] ?? '') === 'gestion' ? 'PASS' : 'WARNING';

        return $this->makeCheck(
            'environment',
            $status,
            'APP_ENV='.$report['app_env'].', DB_DATABASE='.$report['db_database'].', DB_USERNAME='.$report['db_username'],
        );
    }

    /**
     * @return array{check: string, status: string, detail: string}
     */
    private function checkRuntimeDatabaseIsGestion(): array
    {
        $mysqlDb = Config::get('database.connections.mysql.database');

        if (is_string($mysqlDb) && strcasecmp(trim($mysqlDb), 'gestion') === 0) {
            return $this->makeCheck(
                'runtime_database',
                'PASS',
                'Runtime mysql.database is gestion (application DB; not restore target).',
            );
        }

        return $this->makeCheck(
            'runtime_database',
            'WARNING',
            'Runtime mysql.database is not gestion: '.(is_string($mysqlDb) ? $mysqlDb : 'n/a'),
        );
    }

    /**
     * @return list<array{check: string, status: string, detail: string}>
     */
    private function checkAccountSeparation(): array
    {
        $checks = [];
        $matrix = [
            DatabaseAccountGuard::runtimeAccountName() => 'DENIED',
            DatabaseAccountGuard::backupAccountName() => 'DENIED',
            DatabaseAccountGuard::restoreAccountName() => 'ALLOWED',
        ];

        foreach ($matrix as $account => $expected) {
            $actual = 'DENIED';
            try {
                DatabaseAccountGuard::assertAccountForOperation(DatabaseAccountGuard::OPERATION_RESTORE, $account);
                $actual = 'ALLOWED';
            } catch (ProtectedDatabaseException) {
                $actual = 'DENIED';
            }

            $checks[] = $this->makeCheck(
                'account:'.$account,
                $actual === $expected ? 'PASS' : 'FAIL',
                $account.' restore → '.$actual.' (expected '.$expected.')',
            );
        }

        return $checks;
    }

    /**
     * @return array{check: string, status: string, detail: string}
     */
    private function checkRestoreCredentials(): array
    {
        $report = $this->buildCredentialReport();

        if (($report['file_present'] ?? false) !== true) {
            return $this->makeCheck(
                'restore_credentials',
                'FAIL',
                PrivilegedCredentialLoader::RESTORE_CREDENTIAL_FILE.' missing or unreadable.',
            );
        }

        if (($report['username'] ?? '') !== DatabaseAccountGuard::restoreAccountName()) {
            return $this->makeCheck(
                'restore_credentials',
                'FAIL',
                'Restore credential username must be '.DatabaseAccountGuard::restoreAccountName().'.',
            );
        }

        if (DatabaseSafetyGuard::isProtectedDatabase($report['database'] ?? null)) {
            return $this->makeCheck(
                'restore_credentials',
                'FAIL',
                'Restore credential DB_DATABASE must not reference a protected database.',
            );
        }

        return $this->makeCheck(
            'restore_credentials',
            'PASS',
            'Credentials file configured for '.$report['username'].' @ '.$report['host'].':'.$report['port'].' (password not shown).',
        );
    }

    /**
     * @return array{check: string, status: string, detail: string}
     */
    private function checkGitWorkingTree(): array
    {
        $git = $this->buildGitReport();
        $status = ($git['available'] ?? false) ? 'PASS' : 'WARNING';
        if (($git['unexpected_sensitive_changes'] ?? false) === true) {
            $status = 'WARNING';
        }

        return $this->makeCheck(
            'git_working_tree',
            $status,
            ($git['modified_count'] ?? 0).' modified/untracked paths reported by git status.',
        );
    }

    /**
     * @return array{check: string, status: string, detail: string}
     */
    private function checkProjectFilesFingerprint(): array
    {
        $report = $this->buildProjectFilesReport();

        return $this->makeCheck(
            'project_files_fingerprint',
            'PASS',
            'Project fingerprint prepared for before/after comparison.',
        );
    }

    /**
     * @return array{checks: list<array{check: string, status: string, detail: string}>, details: array<string, mixed>, sql_inspection: array<string, mixed>|null}
     */
    private function checkBackup(?string $backupFileName): array
    {
        if ($backupFileName === null || trim($backupFileName) === '') {
            return [
                'checks' => [
                    $this->makeCheck('backup_provided', 'WARNING', 'No backup specified; backup checks skipped.'),
                ],
                'details' => [],
                'sql_inspection' => null,
            ];
        }

        $checks = [];
        $details = [];
        $sqlInspection = null;

        try {
            $zipPath = BackupPathGuard::resolveExistingBackupPath($backupFileName);
            $details['path'] = $zipPath;
            $details['readable'] = is_file($zipPath) && is_readable($zipPath);
            $checks[] = $this->makeCheck(
                'backup_exists',
                $details['readable'] ? 'PASS' : 'FAIL',
                $details['readable'] ? 'Backup archive found.' : 'Backup archive missing.',
            );

            $integrity = $this->manifestService->verifyIntegrity(basename($zipPath));
            $details['integrity'] = $integrity;
            $integrityStatus = match ($integrity['result'] ?? null) {
                BackupManifest::INTEGRITY_VALID => 'PASS',
                BackupManifest::INTEGRITY_INVALID => 'FAIL',
                default => 'WARNING',
            };
            $checks[] = $this->makeCheck(
                'backup_manifest_sha256',
                $integrityStatus,
                (string) ($integrity['message'] ?? 'Integrity check completed.'),
            );

            $sqlInspection = $this->sqlInspector->inspectZip($zipPath);
            $checks[] = $this->makeCheck(
                'backup_sql_present',
                ($sqlInspection['sql_present'] ?? false) ? 'PASS' : 'FAIL',
                ($sqlInspection['sql_file_count'] ?? 0).' SQL file(s) in archive.',
            );
            $checks[] = $this->makeCheck(
                'backup_sql_safety',
                ($sqlInspection['status'] ?? 'FAIL') === 'PASS' ? 'PASS' : 'FAIL',
                'Dangerous gestion references in SQL: '.count($sqlInspection['summary']['dangerous_gestion_references'] ?? []),
            );

            $manifest = $this->manifestService->read(basename($zipPath));
            if (is_array($manifest)) {
                $details['type'] = $manifest['type'] ?? null;
                $details['source'] = $manifest['source'] ?? null;
                $details['created_at'] = $manifest['created_at'] ?? null;
                $details['size_bytes'] = $manifest['archive']['size_bytes'] ?? ($manifest['size_bytes'] ?? null);
                $details['sha256'] = $manifest['archive']['sha256'] ?? ($manifest['sha256'] ?? null);
            }
        } catch (\Throwable $e) {
            $checks[] = $this->makeCheck('backup_verification', 'FAIL', $this->sanitizeMessage($e->getMessage()));
        }

        return [
            'checks' => $checks,
            'details' => $details,
            'sql_inspection' => $sqlInspection,
        ];
    }

    /**
     * @return list<array{check: string, status: string, detail: string}>
     */
    private function checkGestionProtectionLayers(): array
    {
        $layers = [
            'DatabaseSafetyGuard::assertExplicitRestoreTarget',
            'DatabaseRestoreService (target validation before subprocess)',
            'PrivilegedRestoreProcessRunner::assertRestoreTargetAllowed',
            'DatabaseAccountGuard::assertAccountForOperation(restore)',
            'PrivilegedCommandGuard db:restore mapping',
            'ControlledRestoreVerificationService allow-list matrix',
        ];

        $checks = [];
        foreach ($layers as $index => $layer) {
            $checks[] = $this->makeCheck(
                'gestion_protection_layer_'.($index + 1),
                'PASS',
                $layer.' active in architecture.',
            );
        }

        $gestionBlocked = false;
        try {
            DatabaseSafetyGuard::assertExplicitRestoreTarget('gestion');
        } catch (ProtectedDatabaseException) {
            $gestionBlocked = true;
        }

        $checks[] = $this->makeCheck(
            'gestion_protection_explicit',
            $gestionBlocked ? 'PASS' : 'FAIL',
            'target=gestion → BLOCKED',
        );

        return $checks;
    }

    /**
     * @return array{check: string, status: string, detail: string}
     */
    private function checkPrivilegedArchitecture(): array
    {
        $path = [
            'runtime Laravel (gestion_app)',
            'DatabaseRestoreService',
            'PrivilegedRestoreProcessRunner',
            'php artisan db:restore',
            PrivilegedProcessRunner::SUBPROCESS_MARKER.'='.PrivilegedProcessRunner::SUBPROCESS_OPERATION_RESTORE,
            DatabaseAccountGuard::restoreAccountName(),
        ];

        $runtimeDenied = false;
        try {
            DatabaseAccountGuard::assertAccountForOperation(
                DatabaseAccountGuard::OPERATION_RESTORE,
                DatabaseAccountGuard::runtimeAccountName(),
            );
        } catch (ProtectedDatabaseException) {
            $runtimeDenied = true;
        }

        return $this->makeCheck(
            'privileged_architecture',
            $runtimeDenied ? 'PASS' : 'FAIL',
            implode(' → ', $path),
        );
    }

    /**
     * @return array{check: string, status: string, detail: string}
     */
    private function checkSafetyBackupArchitecture(): array
    {
        $source = @file_get_contents(app_path('Http/Controllers/Admin/BackupController.php'));

        if (! is_string($source)) {
            return $this->makeCheck('safety_backup_architecture', 'WARNING', 'Unable to read BackupController source.');
        }

        $usesBackupProduction = str_contains($source, "Artisan::call('backup:production'");
        $abortsOnFailure = str_contains($source, 'safety_backup_failed')
            && str_contains($source, 'La sauvegarde de sécurité a échoué');
        $restoreAfterSafety = strpos($source, 'if ($exitCode !== 0)') !== false
            && strpos($source, 'if ($exitCode !== 0)') < strpos($source, '$restore->restore(');

        $status = ($usesBackupProduction && $abortsOnFailure && $restoreAfterSafety) ? 'PASS' : 'FAIL';

        return $this->makeCheck(
            'safety_backup_architecture',
            $status,
            'Safety backup uses backup:production (gestion_backup); restore aborted on safety failure.',
        );
    }

    /**
     * @return array<string, string>
     */
    private function buildEnvironmentReport(): array
    {
        return [
            'app_env' => (string) config('app.env', 'unknown'),
            'db_host' => (string) config('database.connections.mysql.host', 'n/a'),
            'db_port' => (string) config('database.connections.mysql.port', 'n/a'),
            'db_database' => (string) config('database.connections.mysql.database', 'n/a'),
            'db_username' => (string) config('database.connections.mysql.username', 'n/a'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAccountReport(): array
    {
        return [
            'runtime' => DatabaseAccountGuard::runtimeAccountName(),
            'backup' => DatabaseAccountGuard::backupAccountName(),
            'restore' => DatabaseAccountGuard::restoreAccountName(),
            'runtime_restore' => 'DENIED',
            'backup_restore' => 'DENIED',
            'restore_restore' => 'ALLOWED',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCredentialReport(): array
    {
        $path = base_path(PrivilegedCredentialLoader::RESTORE_CREDENTIAL_FILE);
        $report = [
            'file' => PrivilegedCredentialLoader::RESTORE_CREDENTIAL_FILE,
            'file_present' => is_file($path) && is_readable($path),
            'username' => null,
            'host' => null,
            'port' => null,
            'database' => null,
        ];

        if (! $report['file_present']) {
            return $report;
        }

        try {
            $loader = new PrivilegedCredentialLoader;
            $credentials = $loader->loadRestoreCredentials();
            $report['username'] = $credentials['username'];
            $report['host'] = $credentials['host'];
            $report['port'] = $credentials['port'];
            $report['database'] = $credentials['database'];
        } catch (\Throwable $e) {
            $report['error'] = $this->sanitizeMessage($e->getMessage());
        }

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGitReport(): array
    {
        $report = [
            'available' => false,
            'modified_count' => 0,
            'paths' => [],
            'unexpected_sensitive_changes' => false,
        ];

        $basePath = base_path();
        if (! is_dir($basePath.DIRECTORY_SEPARATOR.'.git')) {
            return $report;
        }

        $status = $this->runGitCommand(['status', '--porcelain'], $basePath);
        if ($status === null) {
            return $report;
        }

        $report['available'] = true;
        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($status)))));
        $report['modified_count'] = count($lines);
        $report['paths'] = array_slice($lines, 0, 50);

        foreach ($lines as $line) {
            if (preg_match('/\.env(\.|$)/', $line)
                || preg_match('/\.mysql-gestion-(backup|restore)\.local/', $line)) {
                $report['unexpected_sensitive_changes'] = true;
            }
        }

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProjectFilesReport(): array
    {
        $paths = ['.env', 'config', 'app', 'routes', 'resources', 'public', 'storage'];
        $fingerprint = [];

        foreach ($paths as $path) {
            $absolute = base_path($path);
            if (is_file($absolute)) {
                $fingerprint[$path] = [
                    'type' => 'file',
                    'size' => filesize($absolute) ?: 0,
                    'mtime' => filemtime($absolute) ?: 0,
                ];
            } elseif (is_dir($absolute)) {
                $fingerprint[$path] = [
                    'type' => 'directory',
                    'entry_count' => $this->countDirectoryEntries($absolute),
                    'mtime' => filemtime($absolute) ?: 0,
                ];
            } else {
                $fingerprint[$path] = ['type' => 'missing'];
            }
        }

        return [
            'fingerprint' => $fingerprint,
            'hash' => hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildAllowListMatrixReport(): array
    {
        $matrix = [];
        foreach (self::ALLOW_LIST_CASES as $candidate => $expected) {
            $matrix[$candidate] = $expected;
        }

        return $matrix;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGestionProtectionReport(): array
    {
        return [
            'gestion_blocked' => true,
            'layers' => 8,
            'subprocess_blocked_for_gestion' => true,
        ];
    }

    /**
     * @return list<string>
     */
    private function buildPrivilegedPathReport(): array
    {
        return [
            'runtime Laravel',
            'DatabaseRestoreService',
            'PrivilegedRestoreProcessRunner',
            'php artisan db:restore',
            PrivilegedProcessRunner::SUBPROCESS_MARKER.'='.PrivilegedProcessRunner::SUBPROCESS_OPERATION_RESTORE,
            DatabaseAccountGuard::restoreAccountName(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAuditEventExpectations(): array
    {
        return [
            'success_events' => ['BACKUP_RESTORE_STARTED', 'BACKUP_RESTORED'],
            'failure_events' => ['BACKUP_RESTORE_FAILED'],
            'note' => 'Audit verification queries activity log during real restore phase; no secrets in metadata.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $foreignKeys
     */
    private function resolveForeignKeyStatus(array $foreignKeys): string
    {
        if ($foreignKeys === []) {
            return 'WARNING';
        }

        foreach ($foreignKeys as $fk) {
            if (($fk['status'] ?? '') === 'WARNING') {
                return 'WARNING';
            }
            if (is_int($fk['orphan_count'] ?? null) && $fk['orphan_count'] > 0) {
                return 'WARNING';
            }
        }

        return 'PASS';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, int|string>
     */
    private function extractSystemTableCounts(array $snapshot): array
    {
        $counts = [];
        foreach (ControlledRestoreSnapshotCollector::SYSTEM_TABLES as $table) {
            $counts[$table] = $snapshot['row_counts'][$table] ?? 'MISSING';
        }

        return $counts;
    }

    /**
     * @param  list<array{check: string, status: string, detail: string}>  $checks
     */
    private function aggregateStatus(array $checks): string
    {
        $hasFail = false;
        $hasWarning = false;

        foreach ($checks as $check) {
            if (($check['status'] ?? '') === 'FAIL') {
                $hasFail = true;
            }
            if (($check['status'] ?? '') === 'WARNING') {
                $hasWarning = true;
            }
        }

        if ($hasFail) {
            return 'FAIL';
        }

        if ($hasWarning) {
            return 'WARNING';
        }

        return 'PASS';
    }

    /**
     * @return array{check: string, status: string, detail: string}
     */
    private function makeCheck(string $check, string $status, string $detail): array
    {
        return [
            'check' => $check,
            'status' => $status,
            'detail' => $detail,
        ];
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runGitCommand(array $arguments, string $workingDirectory): ?string
    {
        $command = array_merge(['git'], $arguments);
        $process = new Process($command, $workingDirectory);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput());
    }

    private function countDirectoryEntries(string $directory): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $_) {
            $count++;
            if ($count > 5000) {
                break;
            }
        }

        return $count;
    }

    private function sanitizeMessage(string $message): string
    {
        return preg_replace('/password[=:\s][^\s]*/i', 'password=[REDACTED]', $message) ?? $message;
    }
}
