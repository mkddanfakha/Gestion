<?php

namespace App\Database\Console;

use App\Database\DatabaseSafetyGuard;
use Illuminate\Database\Console\Seeds\SeedCommand as BaseSeedCommand;

/**
 * Blocks db:seed against protected business databases (e.g. gestion).
 */
class ProtectedSeedCommand extends BaseSeedCommand
{
    public function handle()
    {
        DatabaseSafetyGuard::assertDestructiveOperationAllowed(
            'db:seed',
            $this->input->getOption('database'),
        );

        return parent::handle();
    }
}
