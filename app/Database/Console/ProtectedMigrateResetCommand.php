<?php

namespace App\Database\Console;

use App\Database\DatabaseSafetyGuard;
use Illuminate\Database\Console\Migrations\ResetCommand as BaseResetCommand;

class ProtectedMigrateResetCommand extends BaseResetCommand
{
    public function handle()
    {
        DatabaseSafetyGuard::assertDestructiveOperationAllowed(
            'migrate:reset',
            $this->input->getOption('database'),
        );

        return parent::handle();
    }
}
