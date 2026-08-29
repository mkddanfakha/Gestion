<?php

namespace App\Database;

use ZipArchive;

/**
 * Read-only inspection of Spatie backup ZIP archives (no SQL import).
 */
class BackupArchiveInspector
{
    /**
     * Tables used to judge whether a dump contains métier data.
     *
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
        'inventory_sessions',
        'inventory_items',
        'stock_movements',
        'attachments',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function inspect(string $zipPath): array
    {
        if (! is_file($zipPath)) {
            return [
                'readable' => false,
                'error' => 'file_not_found',
                'path' => $zipPath,
            ];
        }

        $sha256 = hash_file('sha256', $zipPath) ?: null;
        $size = filesize($zipPath) ?: 0;

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            return [
                'readable' => false,
                'error' => 'zip_unreadable',
                'path' => $zipPath,
                'size_bytes' => $size,
                'sha256' => $sha256,
            ];
        }

        $entries = [];
        $sqlEntries = [];
        $hasAttachments = false;
        $hasDotEnv = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $stat = $zip->statIndex($i);
            $entries[] = $name;

            if (str_ends_with(strtolower($name), '.sql')) {
                $sqlEntries[] = [
                    'name' => $name,
                    'size' => $stat['size'] ?? 0,
                ];
            }

            if (str_contains(str_replace('\\', '/', $name), 'attachments/')) {
                $hasAttachments = true;
            }

            if (preg_match('/(^|\/)\.env$/i', $name)) {
                $hasDotEnv = true;
            }
        }

        $sqlAnalysis = [
            'present' => $sqlEntries !== [],
            'files' => $sqlEntries,
            'insert_counts' => [],
            'create_table_count' => 0,
            'business_inserts' => 0,
            'looks_like_schema_only' => true,
            'contains_secret_markers' => false,
        ];

        foreach ($sqlEntries as $sqlEntry) {
            $contents = $zip->getFromName($sqlEntry['name']);
            if (! is_string($contents)) {
                continue;
            }

            $sqlAnalysis['create_table_count'] += preg_match_all('/CREATE TABLE/i', $contents) ?: 0;
            $sqlAnalysis['contains_secret_markers'] = $sqlAnalysis['contains_secret_markers']
                || (bool) preg_match('/APP_KEY=base64:|PUSHER_APP_SECRET=|MAIL_PASSWORD=/', $contents);

            foreach (self::BUSINESS_TABLES as $table) {
                $count = preg_match_all('/INSERT INTO [`\']?'.$table.'[`\']?/i', $contents) ?: 0;
                $sqlAnalysis['insert_counts'][$table] = ($sqlAnalysis['insert_counts'][$table] ?? 0) + $count;
                $sqlAnalysis['business_inserts'] += $count;
            }
        }

        $sqlAnalysis['looks_like_schema_only'] = $sqlAnalysis['present']
            && $sqlAnalysis['business_inserts'] === 0;

        $zip->close();

        $manifest = [
            'application' => 'MKD-Pro',
            'inspected_at' => now()->toIso8601String(),
            'archive' => basename($zipPath),
            'size_bytes' => $size,
            'sha256' => $sha256,
            'entry_count' => count($entries),
            'sql' => $sqlAnalysis,
            'has_attachments_paths' => $hasAttachments,
            'contains_dotenv' => $hasDotEnv,
            'backup_type' => $sqlAnalysis['present'] ? 'archive_with_sql' : 'archive_without_sql',
            'verdict' => self::verdict($sqlAnalysis, $size, $hasDotEnv),
        ];

        return array_merge($manifest, ['readable' => true, 'path' => $zipPath]);
    }

    /**
     * @param  array<string, mixed>  $sqlAnalysis
     */
    private static function verdict(array $sqlAnalysis, int $size, bool $hasDotEnv): string
    {
        if (! $sqlAnalysis['present']) {
            if ($size < 1024) {
                return 'INVALID_TOO_SMALL';
            }

            return 'INVALID_NO_SQL';
        }

        if ($hasDotEnv) {
            return 'CAUTION_CONTAINS_DOTENV';
        }

        if ($sqlAnalysis['looks_like_schema_only']) {
            return 'SCHEMA_ONLY';
        }

        return 'HAS_BUSINESS_INSERTS';
    }
}
