<?php

namespace App\Database;

/**
 * Sanitized result of a privileged subprocess (secrets redacted from output).
 */
final class PrivilegedProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $errorOutput,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function combinedOutput(): string
    {
        return trim($this->output."\n".$this->errorOutput);
    }
}
