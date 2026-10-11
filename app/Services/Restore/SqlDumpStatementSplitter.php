<?php

namespace App\Services\Restore;

use RuntimeException;

/**
 * Splits mysqldump SQL into executable statements without stripping MySQL/MariaDB conditional comments.
 */
final class SqlDumpStatementSplitter
{
    /**
     * @return list<string>
     */
    public function split(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $len = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $prev = $i > 0 ? $sql[$i - 1] : '';

            if ($inSingle) {
                if ($ch === "'" && $prev !== '\\') {
                    if ($i + 1 < $len && $sql[$i + 1] === "'") {
                        $buffer .= "''";
                        $i++;

                        continue;
                    }
                    $inSingle = false;
                }
                $buffer .= $ch;

                continue;
            }

            if ($inDouble) {
                if ($ch === '"' && $prev !== '\\') {
                    $inDouble = false;
                }
                $buffer .= $ch;

                continue;
            }

            if ($inBacktick) {
                if ($ch === '`') {
                    $inBacktick = false;
                }
                $buffer .= $ch;

                continue;
            }

            if ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }

                continue;
            }

            if ($ch === '#') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }

                continue;
            }

            if ($ch === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                $inner = $this->extractBlockCommentInner($sql, $i + 2, $len);
                $i = $inner['endIndex'];
                if ($inner['executable']) {
                    $buffer .= $inner['content'];
                }

                continue;
            }

            if ($this->startsDelimiterDirective($sql, $i)) {
                throw new RuntimeException(
                    'SQL dump uses DELIMITER directives — not supported for safe import.',
                );
            }

            if ($ch === "'") {
                $inSingle = true;
                $buffer .= $ch;

                continue;
            }
            if ($ch === '"') {
                $inDouble = true;
                $buffer .= $ch;

                continue;
            }
            if ($ch === '`') {
                $inBacktick = true;
                $buffer .= $ch;

                continue;
            }

            if ($ch === ';') {
                $statement = trim($buffer);
                $buffer = '';
                if ($statement !== '') {
                    $statements[] = $statement;
                }

                continue;
            }

            $buffer .= $ch;
        }

        if ($inSingle || $inDouble || $inBacktick) {
            throw new RuntimeException('SQL dump contains an unclosed string or quoted identifier.');
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }

    /**
     * Fail-closed validation before any DROP/CREATE on the restore target connection.
     *
     * @param  list<string>  $statements
     */
    public function validateExecutableStatements(array $statements): void
    {
        $executableCount = 0;

        foreach ($statements as $statement) {
            if ($this->shouldSkipStatement($statement)) {
                continue;
            }

            $executableCount++;

            $normalized = ltrim($statement);
            if (preg_match('/^(DROP|CREATE)\s+DATABASE\b/i', $normalized)) {
                throw new RuntimeException(
                    'SQL dump contains forbidden database-level DDL — refused before import.',
                );
            }
        }

        if ($executableCount === 0) {
            throw new RuntimeException('SQL dump contains no executable statements after sanitization.');
        }
    }

    /**
     * @return array{executable: bool, content: string, endIndex: int}
     */
    private function extractBlockCommentInner(string $sql, int $start, int $len): array
    {
        $pos = $start;
        $executable = false;

        if ($pos + 1 < $len && $sql[$pos] === 'M' && $sql[$pos + 1] === '!') {
            $executable = true;
            $pos += 2;
            $pos = $this->skipVersionDigits($sql, $pos, $len);
        } elseif ($pos < $len && $sql[$pos] === '!') {
            $executable = true;
            $pos++;
            $pos = $this->skipVersionDigits($sql, $pos, $len);
        }

        $contentStart = $pos;
        while ($pos + 1 < $len) {
            if ($sql[$pos] === '*' && $sql[$pos + 1] === '/') {
                $content = $executable ? substr($sql, $contentStart, $pos - $contentStart) : '';

                return [
                    'executable' => $executable,
                    'content' => $content,
                    'endIndex' => $pos + 1,
                ];
            }
            $pos++;
        }

        throw new RuntimeException('SQL dump contains an unclosed block comment.');
    }

    private function skipVersionDigits(string $sql, int $pos, int $len): int
    {
        while ($pos < $len && ctype_digit($sql[$pos])) {
            $pos++;
        }

        return $pos;
    }

    private function startsDelimiterDirective(string $sql, int $i): bool
    {
        if ($i + 9 > strlen($sql)) {
            return false;
        }

        return strcasecmp(substr($sql, $i, 9), 'DELIMITER') === 0
            && ($i + 9 >= strlen($sql) || ctype_space($sql[$i + 9]));
    }

    /**
     * Statements mysqldump emits that must not run during controlled restore (target is explicit).
     */
    public function shouldSkipStatement(string $statement): bool
    {
        $normalized = ltrim($statement);

        if ($normalized === '') {
            return true;
        }

        return (bool) preg_match(
            '/^(USE|CREATE\s+DATABASE|DROP\s+DATABASE|LOCK\s+TABLES|UNLOCK\s+TABLES)\b/i',
            $normalized,
        );
    }
}
