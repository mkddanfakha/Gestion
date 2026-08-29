<?php

namespace App\Database\Console;

use App\Database\DatabaseSafetyGuard;
use Illuminate\Database\Console\Migrations\RefreshCommand as BaseRefreshCommand;

class ProtectedMigrateRefreshCommand extends BaseRefreshCommand
{
    public function handle()
    {
        DatabaseSafetyGuard::assertDestructiveOperationAllowed(
            'migrate:refresh',
            $this->input->getOption('database'),
        );

        return parent::handle();
    }
}
