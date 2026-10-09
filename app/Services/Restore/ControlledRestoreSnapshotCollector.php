<?php

namespace App\Services\Restore;

use App\Database\DatabaseSafetyGuard;
use App\Database\PrivilegedCredentialLoader;
use App\Database\RestoreDatabaseTargetResolver;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Read-only database snapshots for controlled restore protocol (no writes).
 */
class ControlledRestoreSnapshotCollector
{
    /**
     * @var list<string>
     */
    public const BUSINESS_TABLES = [
        'users',
        'companies',
        'customers',
        'products',
        'categories',
        'sales',
        'sale_items',
        'quotes',
        'quote_items',
        'expenses',
        'suppliers',
        'purchase_orders',
        'purchase_order_items',
        'delivery_notes',
        'delivery_note_items',
    ];

    /**
     * @var list<string>
     */
    public const SYSTEM_TABLES = [
        'sessions',
        'jobs',
        'failed_jobs',
        'permissions',
        'user_permissions',
        'migrations',
    ];

    /**
     * @var list<array{table: string, column: string, ref_table: string, ref_column: string}>
     */
    public const FOREIGN_KEY_CHECKS = [
        ['table' => 'sales', 'column' => 'customer_id', 'ref_table' => 'customers', 'ref_column' => 'id'],
        ['table' => 'sale_items', 'column' => 'sale_id', 'ref_table' => 'sales', 'ref_column' => 'id'],
        ['table' => 'sale_items', 'column' => 'product_id', 'ref_table' => 'products', 'ref_column' => 'id'],
        ['table' => 'quotes', 'column' => 'customer_id', 'ref_table' => 'customers', 'ref_column' => 'id'],
        ['table' => 'quote_items', 'column' => 'quote_id', 'ref_table' => 'quotes', 'ref_column' => 'id'],
        ['table' => 'quote_items', 'column' => 'product_id', 'ref_table' => 'products', 'ref_column' => 'id'],
        ['table' => 'expenses', 'column' => 'user_id', 'ref_table' => 'users', 'ref_column' => 'id'],
        ['table' => 'purchase_orders', 'column' => 'supplier_id', 'ref_table' => 'suppliers', 'ref_column' => 'id'],
        ['table' => 'purchase_order_items', 'column' => 'purchase_order_id', 'ref_table' => 'purchase_orders', 'ref_column' => 'id'],
        ['table' => 'purchase_order_items', 'column' => 'product_id', 'ref_table' => 'products', 'ref_column' => 'id'],
        ['table' => 'delivery_notes', 'column' => 'purchase_order_id', 'ref_table' => 'purchase_orders', 'ref_column' => 'id'],
        ['table' => 'delivery_note_items', 'column' => 'delivery_note_id', 'ref_table' => 'delivery_notes', 'ref_column' => 'id'],
        ['table' => 'delivery_note_items', 'column' => 'product_id', 'ref_table' => 'products', 'ref_column' => 'id'],
    ];

    public function __construct(
        private RestoreDatabaseTargetResolver $targetResolver = new RestoreDatabaseTargetResolver,
        private PrivilegedCredentialLoader $credentialLoader = new PrivilegedCredentialLoader,
    ) {}

    /**
     * Snapshot of the active application database via the Laravel mysql runtime connection (read-only).
     *
     * @return array<string, mixed>
     */
    public function collectApplicationDatabaseSnapshot(): array
    {
        $physicalDatabase = DatabaseSafetyGuard::resolveDatabaseName('mysql');
        $logicalDatabase = $this->applicationLogicalDatabaseName();

        if ($physicalDatabase === '') {
            return $this->failedSnapshot(
                $logicalDatabase,
                null,
                'application',
                'Application database name could not be resolved from mysql connection config.',
            );
        }

        if (config('database.connections.mysql.driver') !== 'mysql') {
            return $this->failedSnapshot(
                $logicalDatabase,
                $physicalDatabase,
                'application',
                'MySQL connection unavailable (read-only snapshot skipped).',
                'WARNING',
            );
        }

        try {
            $pdo = DB::connection('mysql')->getPdo();
        } catch (\Throwable $e) {
            return $this->failedSnapshot(
                $logicalDatabase,
                $physicalDatabase,
                'application',
                $this->sanitizeMessage($e->getMessage()),
                'WARNING',
            );
        }

        if (! $pdo instanceof PDO) {
            return $this->failedSnapshot(
                $logicalDatabase,
                $physicalDatabase,
                'application',
                'Application mysql connection did not yield a PDO instance.',
            );
        }

        return $this->collectReadOnlySnapshot(
            $logicalDatabase,
            $physicalDatabase,
            'application',
            $pdo,
        );
    }

    /**
     * Snapshot of an allow-listed restore target via the dedicated restore account (read-only).
     *
     * @return array<string, mixed>
     */
    public function collectRestoreTargetDatabaseSnapshot(string $logicalTarget): array
    {
        $logical = DatabaseSafetyGuard::normalizeExplicitTarget($logicalTarget);

        try {
            DatabaseSafetyGuard::assertExplicitRestoreTarget($logical);
            $physicalDatabase = $this->targetResolver->resolvePhysicalName($logical);
            $pdo = $this->openRestoreReadOnlyPdo();
        } catch (\Throwable $e) {
            return $this->failedSnapshot(
                $logical,
                null,
                'restore',
                $this->sanitizeMessage($e->getMessage()),
            );
        }

        return $this->collectReadOnlySnapshot(
            $logical,
            $physicalDatabase,
            'restore',
            $pdo,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function collectReadOnlySnapshot(
        string $logicalDatabase,
        string $physicalDatabase,
        string $connectionMode,
        PDO $pdo,
    ): array {
        $snapshot = [
            'database' => $logicalDatabase,
            'logical_database' => $logicalDatabase,
            'physical_database' => $physicalDatabase,
            'connection_mode' => $connectionMode,
            'collected_at' => now()->toIso8601String(),
            'connection_available' => true,
            'tables' => [],
            'row_counts' => [],
            'schema_fingerprint' => null,
            'foreign_keys' => [],
            'status' => 'WARNING',
            'detail' => '',
        ];

        try {
            $snapshot['tables'] = $this->listTables($pdo, $physicalDatabase);
            $snapshot['schema_fingerprint'] = $this->buildSchemaFingerprint($pdo, $physicalDatabase);
            $snapshot['row_counts'] = $this->collectRowCounts($pdo, $physicalDatabase, array_merge(
                self::BUSINESS_TABLES,
                self::SYSTEM_TABLES,
            ));
            $snapshot['foreign_keys'] = $this->collectForeignKeyOrphansForDatabase($pdo, $physicalDatabase);
            $snapshot['status'] = $this->resolveSnapshotStatus($snapshot);
            $snapshot['detail'] = 'Read-only snapshot collected via '.$connectionMode.' connection.';

            return $snapshot;
        } catch (\Throwable $e) {
            return $this->failedSnapshot(
                $logicalDatabase,
                $physicalDatabase,
                $connectionMode,
                $this->sanitizeMessage($e->getMessage()),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function failedSnapshot(
        string $logicalDatabase,
        ?string $physicalDatabase,
        string $connectionMode,
        string $detail,
        string $status = 'FAIL',
    ): array {
        return [
            'database' => $logicalDatabase,
            'logical_database' => $logicalDatabase,
            'physical_database' => $physicalDatabase,
            'connection_mode' => $connectionMode,
            'collected_at' => now()->toIso8601String(),
            'connection_available' => false,
            'tables' => [],
            'row_counts' => [],
            'schema_fingerprint' => null,
            'foreign_keys' => [],
            'status' => $status,
            'detail' => $detail,
        ];
    }

    private function applicationLogicalDatabaseName(): string
    {
        $protected = DatabaseSafetyGuard::protectedDatabases();

        return $protected[0] ?? 'gestion';
    }

    protected function openRestoreReadOnlyPdo(): PDO
    {
        $credentials = $this->credentialLoader->loadRestoreCredentials();

        $host = $credentials['host'];
        $port = $credentials['port'] ?? '3306';

        return new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $credentials['username'],
            $credentials['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 30,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>
     */
    public function compareSnapshots(array $before, array $after): array
    {
        $unchanged = ($before['schema_fingerprint'] ?? null) === ($after['schema_fingerprint'] ?? null)
            && ($before['row_counts'] ?? []) === ($after['row_counts'] ?? []);

        return [
            'database' => $before['database'] ?? ($after['database'] ?? 'unknown'),
            'schema_fingerprint_before' => $before['schema_fingerprint'] ?? null,
            'schema_fingerprint_after' => $after['schema_fingerprint'] ?? null,
            'row_counts_before' => $before['row_counts'] ?? [],
            'row_counts_after' => $after['row_counts'] ?? [],
            'unchanged' => $unchanged,
            'status' => $unchanged ? 'PASS' : 'FAIL',
            'detail' => $unchanged ? 'Schema fingerprint and row counts match.' : 'Differences detected between snapshots.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectForeignKeyOrphansForDatabase(PDO $pdo, string $physicalDatabase): array
    {
        $results = [];

        foreach (self::FOREIGN_KEY_CHECKS as $check) {
            $orphanCount = null;
            $status = 'WARNING';
            $detail = '';

            try {
                if (! $this->tableExists($pdo, $physicalDatabase, $check['table'])
                    || ! $this->tableExists($pdo, $physicalDatabase, $check['ref_table'])) {
                    $status = 'WARNING';
                    $detail = 'Table missing for FK check.';
                } else {
                    $sql = sprintf(
                        'SELECT COUNT(*) AS orphan_count FROM `%s`.`%s` child LEFT JOIN `%s`.`%s` parent ON child.`%s` = parent.`%s` WHERE child.`%s` IS NOT NULL AND parent.`%s` IS NULL',
                        $this->quoteIdentifier($physicalDatabase),
                        $this->quoteIdentifier($check['table']),
                        $this->quoteIdentifier($physicalDatabase),
                        $this->quoteIdentifier($check['ref_table']),
                        $this->quoteIdentifier($check['column']),
                        $this->quoteIdentifier($check['ref_column']),
                        $this->quoteIdentifier($check['column']),
                        $this->quoteIdentifier($check['ref_column']),
                    );

                    $row = $this->selectOne($pdo, $sql);
                    $orphanCount = (int) ($row['orphan_count'] ?? 0);
                    $status = 'PASS';
                    $detail = $orphanCount === 0 ? 'No orphan rows.' : $orphanCount.' orphan row(s).';
                }
            } catch (\Throwable $e) {
                $status = 'WARNING';
                $detail = $this->sanitizeMessage($e->getMessage());
            }

            $results[] = [
                'table' => $check['table'],
                'relation' => $check['table'].'.'.$check['column'].' → '.$check['ref_table'].'.'.$check['ref_column'],
                'orphan_count' => $orphanCount,
                'status' => $status,
                'detail' => $detail,
            ];
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function listTables(PDO $pdo, string $database): array
    {
        $rows = $this->selectAll(
            $pdo,
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
            [$database],
        );

        return array_values(array_map(static fn (array $row): string => (string) $row['name'], $rows));
    }

    private function buildSchemaFingerprint(PDO $pdo, string $database): ?string
    {
        $rows = $this->selectAll(
            $pdo,
            'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ?
             ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$database],
        );

        if ($rows === []) {
            return null;
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int|string>
     */
    private function collectRowCounts(PDO $pdo, string $database, array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            if (! $this->tableExists($pdo, $database, $table)) {
                $counts[$table] = 'MISSING';

                continue;
            }

            try {
                $row = $this->selectOne(
                    $pdo,
                    sprintf(
                        'SELECT COUNT(*) AS c FROM `%s`.`%s`',
                        $this->quoteIdentifier($database),
                        $this->quoteIdentifier($table),
                    ),
                );
                $counts[$table] = (int) ($row['c'] ?? 0);
            } catch (\Throwable $e) {
                $counts[$table] = 'ERROR: '.$this->sanitizeMessage($e->getMessage());
            }
        }

        return $counts;
    }

    private function tableExists(PDO $pdo, string $database, string $table): bool
    {
        $row = $this->selectOne(
            $pdo,
            'SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $table],
        );

        return ((int) ($row['c'] ?? 0)) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function selectOne(PDO $pdo, string $sql, array $params = []): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selectAll(PDO $pdo, string $sql, array $params = []): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        if (! is_array($rows)) {
            return [];
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalized[] = $row;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function resolveSnapshotStatus(array $snapshot): string
    {
        $missingBusiness = 0;
        foreach (self::BUSINESS_TABLES as $table) {
            if (($snapshot['row_counts'][$table] ?? null) === 'MISSING') {
                $missingBusiness++;
            }
        }

        if ($missingBusiness > 0) {
            return 'WARNING';
        }

        return 'PASS';
    }

    private function quoteIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }

    private function sanitizeMessage(string $message): string
    {
        return preg_replace('/password[=:\s][^\s]*/i', 'password=[REDACTED]', $message) ?? $message;
    }
}
