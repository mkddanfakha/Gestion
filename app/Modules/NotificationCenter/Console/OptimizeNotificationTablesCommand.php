<?php

namespace App\Modules\NotificationCenter\Console;

use App\Modules\NotificationCenter\Services\NotificationHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OptimizeNotificationTablesCommand extends Command
{
    protected $signature = 'notifications:optimize-tables';

    protected $description = 'Optimise les tables liées aux notifications (MySQL)';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->warn('Optimisation disponible uniquement pour MySQL.');

            return self::SUCCESS;
        }

        $tables = array_filter([
            'notification_reads',
            Schema::hasTable('notification_global_settings') ? 'notification_global_settings' : null,
            Schema::hasTable('notification_type_settings') ? 'notification_type_settings' : null,
            Schema::hasTable('user_notification_preferences') ? 'user_notification_preferences' : null,
        ]);

        foreach ($tables as $table) {
            DB::statement("OPTIMIZE TABLE `{$table}`");
            $this->line("Table optimisée : {$table}");
        }

        NotificationHealthService::markSchedulerRun('notifications:optimize-tables');

        return self::SUCCESS;
    }
}
