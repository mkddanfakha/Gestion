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
        'db:restore' => DatabaseAccountGuard::OPERATION_RESTORE,
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
        $username = null;
        $database = null;

        if ($command === 'migrate') {
            $connection = self::migrateConnectionName($event);
            $username = self::connectionString($connection, 'username');
            $database = self::connectionString($connection, 'database');
        }

        try {
            DatabaseAccountGuard::assertAccountForOperation($operation, $username, $database);
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
        $connection = self::migrateConnectionName($event);
        $driver = config("database.connections.{$connection}.driver");

        return is_string($driver) && strcasecmp($driver, 'mysql') === 0;
    }

    private static function migrateConnectionName(CommandStarting $event): string
    {
        if ($event->input->hasOption('database')) {
            $option = $event->input->getOption('database');
            if (is_string($option) && $option !== '') {
                return $option;
            }
        }

        return (string) config('database.default', 'sqlite');
    }

    private static function connectionString(string $connection, string $key): string
    {
        $value = config("database.connections.{$connection}.{$key}");

        return is_string($value) ? trim($value) : '';
    }
}
