<?php

namespace App\Services\Restore;

interface SqlDumpImporter
{
    public function import(string $sqlPath, string $explicitTarget): void;
}
