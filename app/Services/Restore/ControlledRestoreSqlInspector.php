<?php

namespace App\Services\Restore;

use ZipArchive;

/**
 * Read-only SQL inspection inside backup archives (never imports SQL).
 */
final class ControlledRestoreSqlInspector
{
    /**
     * @var list<string>
     */
    public const DANGEROUS_PATTERNS = [
        '/\bUSE\s+[`\']?gestion[`\']?\b/i',
        '/\bCREATE\s+DATABASE\s+[`\']?gestion[`\']?\b/i',
        '/\bDROP\s+DATABASE\s+[`\']?gestion[`\']?\b/i',
        '/\bALTER\s+DATABASE\s+[`\']?gestion[`\']?\b/i',
    ];

    /**
     * @return array<string, mixed>
     */
    public function inspectZip(string $zipPath): array
    {
        if (! is_file($zipPath)) {
            return [
                'readable' => false,
                'error' => 'file_not_found',
            ];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return [
                'readable' => false,
                'error' => 'zip_unreadable',
            ];
        }

        $sqlFiles = [];
        $aggregate = [
            'create_table_count' => 0,
            'insert_count' => 0,
            'alter_table_count' => 0,
            'foreign_key_count' => 0,
            'dangerous_gestion_references' => [],
            'files' => [],
        ];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (! str_ends_with(strtolower($name), '.sql')) {
                continue;
            }

            $contents = $zip->getFromName($name);
            if (! is_string($contents) || $contents === '') {
                continue;
            }

            $fileReport = $this->inspectSqlContents($contents, $name);
            $sqlFiles[] = $fileReport;
            $aggregate['create_table_count'] += $fileReport['create_table_count'];
            $aggregate['insert_count'] += $fileReport['insert_count'];
            $aggregate['alter_table_count'] += $fileReport['alter_table_count'];
            $aggregate['foreign_key_count'] += $fileReport['foreign_key_count'];
            $aggregate['dangerous_gestion_references'] = array_values(array_unique(array_merge(
                $aggregate['dangerous_gestion_references'],
                $fileReport['dangerous_gestion_references'],
            )));
            $aggregate['files'][] = $name;
        }

        $zip->close();

        return [
            'readable' => true,
            'sql_present' => $sqlFiles !== [],
            'sql_file_count' => count($sqlFiles),
            'files' => $sqlFiles,
            'summary' => $aggregate,
            'status' => $aggregate['dangerous_gestion_references'] === [] ? 'PASS' : 'FAIL',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectSqlContents(string $contents, string $source = 'inline'): array
    {
        $dangerous = [];

        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $contents)) {
                $dangerous[] = $pattern;
            }
        }

        return [
            'source' => $source,
            'create_table_count' => preg_match_all('/\bCREATE\s+TABLE\b/i', $contents) ?: 0,
            'insert_count' => preg_match_all('/\bINSERT\s+INTO\b/i', $contents) ?: 0,
            'alter_table_count' => preg_match_all('/\bALTER\s+TABLE\b/i', $contents) ?: 0,
            'foreign_key_count' => preg_match_all('/\bFOREIGN\s+KEY\b/i', $contents) ?: 0,
            'dangerous_gestion_references' => $dangerous,
            'status' => $dangerous === [] ? 'PASS' : 'FAIL',
        ];
    }
}
