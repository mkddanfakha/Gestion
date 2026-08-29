<?php

namespace App\Database\Console;

use App\Database\DatabaseSafetyGuard;
use Illuminate\Database\Console\Migrations\FreshCommand as BaseFreshCommand;

/**
 * Container replacement for migrate:fresh — blocks protected DBs before any wipe.
 */
class ProtectedMigrateFreshCommand extends BaseFreshCommand
{
    public function handle()
    {
        DatabaseSafetyGuard::assertDestructiveOperationAllowed(
            'migrate:fresh',
            $this->input->getOption('database'),
        );

        return parent::handle();
    }
}
