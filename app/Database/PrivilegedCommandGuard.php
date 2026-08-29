<?php

namespace App\Database;

use Illuminate\Console\Events\CommandStarting;

/**
 * Fail-closed account checks for privileged Artisan commands.
 * --force does not bypass. backup:verify is file-only and is not gated here.
 */
class PrivilegedCommandGuard
{
    /**
     * @var array<string, string>
     */
    private const COMMAND_OPERATIONS = [
        'backup:run' => DatabaseAccountGuard::OPERATION_BACKUP,
        'migrate' => DatabaseAccountGuard::OPERATION_MIGRATION,
    ];

    public function handle(CommandStarting $event): void
    {
        $command = $event->command;
        if ($command === null || ! isset(self::COMMAND_OPERATIONS[$command])) {
            return;
        }

        if ($command === 'migrate' && ! self::migrateUsesMysql($event)) {
            return;
        }

        $operation = self::COMMAND_OPERATIONS[$command];

        try {
            DatabaseAccountGuard::assertAccountForOperation($operation);
        } catch (ProtectedDatabaseException $e) {
            if ($event->output !== null) {
                $event->output->writeln('');
                $event->output->writeln('<error>'.$e->getMessage().'</error>');
                $event->output->writeln('');
            }

            throw $e;
        }
    }

    private static function migrateUsesMysql(CommandStarting $event): bool
    {
        $connection = null;
        if ($event->input->hasOption('database')) {
            $option = $event->input->getOption('database');
            $connection = is_string($option) && $option !== '' ? $option : null;
        }

        $connection ??= (string) config('database.default', 'sqlite');
        $driver = config("database.connections.{$connection}.driver");

        return is_string($driver) && strcasecmp($driver, 'mysql') === 0;
    }
}
