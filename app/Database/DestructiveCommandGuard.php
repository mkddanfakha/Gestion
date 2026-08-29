<?php

namespace App\Database;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\WipeCommand;

class DestructiveCommandGuard
{
    /**
     * Block destructive Artisan commands (CLI and Artisan::call) against protected DBs.
     *
     * --force does not bypass this guard.
     */
    public function handle(CommandStarting $event): void
    {
        if (! DatabaseSafetyGuard::isDestructiveCommand($event->command)) {
            return;
        }

        $connection = null;

        if ($event->input->hasOption('database')) {
            $option = $event->input->getOption('database');
            $connection = is_string($option) && $option !== '' ? $option : null;
        }

        $protected = DatabaseSafetyGuard::isProtectedConnection($connection);

        // Keep Laravel's Prohibitable flag in sync for this process.
        FreshCommand::prohibit($protected);
        RefreshCommand::prohibit($protected);
        ResetCommand::prohibit($protected);
        WipeCommand::prohibit($protected);

        if (! $protected) {
            return;
        }

        $database = DatabaseSafetyGuard::resolveDatabaseName($connection);
        $message = ProtectedDatabaseException::forDestructiveOperation(
            $database,
            (string) $event->command,
            (string) config('app.env', 'unknown'),
        )->getMessage();

        if ($event->output !== null) {
            $event->output->writeln('');
            $event->output->writeln('<error>'.$message.'</error>');
            $event->output->writeln('');
        }

        throw ProtectedDatabaseException::forDestructiveOperation(
            $database,
            (string) $event->command,
            (string) config('app.env', 'unknown'),
        );
    }
}
