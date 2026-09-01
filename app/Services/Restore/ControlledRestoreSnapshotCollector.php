<?php

namespace App\Services\Restore;

use Illuminate\Support\Facades\DB;

/**
 * Read-only database snapshots for controlled restore protocol (no writes).
 */
final class ControlledRestoreSnapshotCollector
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

    /**
     * @return array<string, mixed>
     */
    public function collectDatabaseSnapshot(string $database): array
    {
        $snapshot = [
            'database' => $database,
            'collected_at' => now()->toIso8601String(),
            'connection_available' => false,
            'tables' => [],
            'row_counts' => [],
            'schema_fingerprint' => null,
            'foreign_keys' => [],
            'status' => 'WARNING',
            'detail' => '',
        ];

        if (! $this->mysqlConnectionAvailable()) {
            $snapshot['detail'] = 'MySQL connection unavailable (read-only snapshot skipped).';

            return $snapshot;
        }

        try {
            $snapshot['connection_available'] = true;
            $snapshot['tables'] = $this->listTables($database);
            $snapshot['schema_fingerprint'] = $this->buildSchemaFingerprint($database);
            $snapshot['row_counts'] = $this->collectRowCounts($database, array_merge(
                self::BUSINESS_TABLES,
                self::SYSTEM_TABLES,
            ));
            $snapshot['foreign_keys'] = $this->collectForeignKeyOrphans($database);
            $snapshot['status'] = $this->resolveSnapshotStatus($snapshot);
            $snapshot['detail'] = 'Read-only snapshot collected.';

            return $snapshot;
        } catch (\Throwable $e) {
            $snapshot['status'] = 'WARNING';
            $snapshot['detail'] = $this->sanitizeMessage($e->getMessage());

            return $snapshot;
        }
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
    public function collectForeignKeyOrphans(string $database): array
    {
        $results = [];

        foreach (self::FOREIGN_KEY_CHECKS as $check) {
            $orphanCount = null;
            $status = 'WARNING';
            $detail = '';

            try {
                if (! $this->tableExists($database, $check['table'])
                    || ! $this->tableExists($database, $check['ref_table'])) {
                    $status = 'WARNING';
                    $detail = 'Table missing for FK check.';
                } else {
                    $sql = sprintf(
                        'SELECT COUNT(*) AS orphan_count FROM `%s`.`%s` child LEFT JOIN `%s`.`%s` parent ON child.`%s` = parent.`%s` WHERE child.`%s` IS NOT NULL AND parent.`%s` IS NULL',
                        $this->quoteIdentifier($database),
                        $this->quoteIdentifier($check['table']),
                        $this->quoteIdentifier($database),
                        $this->quoteIdentifier($check['ref_table']),
                        $this->quoteIdentifier($check['column']),
                        $this->quoteIdentifier($check['ref_column']),
                        $this->quoteIdentifier($check['column']),
                        $this->quoteIdentifier($check['ref_column']),
                    );

                    $row = DB::connection('mysql')->selectOne($sql);
                    $orphanCount = (int) ($row->orphan_count ?? 0);
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

    private function mysqlConnectionAvailable(): bool
    {
        if (config('database.connections.mysql.driver') !== 'mysql') {
            return false;
        }

        try {
            DB::connection('mysql')->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function listTables(string $database): array
    {
        $rows = DB::connection('mysql')->select(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
            [$database],
        );

        return array_values(array_map(static fn ($row): string => (string) $row->name, $rows));
    }

    private function buildSchemaFingerprint(string $database): ?string
    {
        $rows = DB::connection('mysql')->select(
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
    private function collectRowCounts(string $database, array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            if (! $this->tableExists($database, $table)) {
                $counts[$table] = 'MISSING';

                continue;
            }

            try {
                $row = DB::connection('mysql')->selectOne(
                    sprintf('SELECT COUNT(*) AS c FROM `%s`.`%s`', $this->quoteIdentifier($database), $this->quoteIdentifier($table)),
                );
                $counts[$table] = (int) ($row->c ?? 0);
            } catch (\Throwable $e) {
                $counts[$table] = 'ERROR: '.$this->sanitizeMessage($e->getMessage());
            }
        }

        return $counts;
    }

    private function tableExists(string $database, string $table): bool
    {
        $row = DB::connection('mysql')->selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $table],
        );

        return ((int) ($row->c ?? 0)) > 0;
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
