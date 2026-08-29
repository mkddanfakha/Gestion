<?php

namespace App\Database\Console;

use App\Database\DatabaseSafetyGuard;
use Illuminate\Database\Console\WipeCommand as BaseWipeCommand;

class ProtectedDbWipeCommand extends BaseWipeCommand
{
    public function handle()
    {
        DatabaseSafetyGuard::assertDestructiveOperationAllowed(
            'db:wipe',
            $this->input->getOption('database'),
        );

        return parent::handle();
    }
}
