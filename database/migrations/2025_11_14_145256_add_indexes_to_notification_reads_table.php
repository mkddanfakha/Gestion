<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->indexExists('notif_reads_user_type_idx')) {
            Schema::table('notification_reads', function (Blueprint $table) {
                $table->index(['user_id', 'notification_type'], 'notif_reads_user_type_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ($this->indexExists('notif_reads_user_type_idx')) {
            Schema::table('notification_reads', function (Blueprint $table) {
                $table->dropIndex('notif_reads_user_type_idx');
            });
        }
    }

    private function indexExists(string $name): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list('notification_reads')");

            foreach ($indexes as $index) {
                if (($index->name ?? null) === $name) {
                    return true;
                }
            }

            return false;
        }

        $database = $connection->getDatabaseName();

        $result = $connection->select(
            'SELECT COUNT(*) AS aggregate FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, 'notification_reads', $name]
        );

        return (int) ($result[0]->aggregate ?? 0) > 0;
    }
};
