<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notification_reads', function (Blueprint $table) {
            if (! Schema::hasColumn('notification_reads', 'type')) {
                $table->string('type', 50)->nullable()->after('notification_type');
            }
            if (! Schema::hasColumn('notification_reads', 'priority')) {
                $table->string('priority', 20)->default('info')->after('type');
            }
            if (! Schema::hasColumn('notification_reads', 'status')) {
                $table->string('status', 20)->default('active')->after('priority');
            }
            if (! Schema::hasColumn('notification_reads', 'audience')) {
                $table->string('audience', 20)->nullable()->after('status');
            }
            if (! Schema::hasColumn('notification_reads', 'entity_type')) {
                $table->string('entity_type', 50)->nullable()->after('audience');
            }
            if (! Schema::hasColumn('notification_reads', 'entity_id')) {
                $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
            }
            if (! Schema::hasColumn('notification_reads', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('entity_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('notification_reads', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable()->after('read_at');
            }
            if (! Schema::hasColumn('notification_reads', 'resolved_by')) {
                $table->foreignId('resolved_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('notification_reads', 'metadata')) {
                $table->json('metadata')->nullable()->after('resolved_by');
            }
        });

        // Normaliser les longueurs si une migration partielle a créé des varchar(255)
        Schema::table('notification_reads', function (Blueprint $table) {
            if (Schema::hasColumn('notification_reads', 'type')) {
                $table->string('type', 50)->nullable()->change();
            }
            if (Schema::hasColumn('notification_reads', 'priority')) {
                $table->string('priority', 20)->default('info')->change();
            }
            if (Schema::hasColumn('notification_reads', 'status')) {
                $table->string('status', 20)->default('active')->change();
            }
            if (Schema::hasColumn('notification_reads', 'audience')) {
                $table->string('audience', 20)->nullable()->change();
            }
            if (Schema::hasColumn('notification_reads', 'entity_type')) {
                $table->string('entity_type', 50)->nullable()->change();
            }
        });

        Schema::table('notification_reads', function (Blueprint $table) {
            $this->addIndexIfMissing($table, ['user_id', 'status'], 'notif_reads_user_status_idx');
            $this->addIndexIfMissing($table, ['type', 'status'], 'notif_reads_type_status_idx');
            $this->addIndexIfMissing($table, ['priority', 'status'], 'notif_reads_priority_status_idx');
        });

        DB::table('notification_reads')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                if ($row->type !== null) {
                    continue;
                }

                $legacyType = $row->notification_type;
                $enumType = config("notifications.legacy_types.{$legacyType}");
                $entityType = $enumType
                    ? config("notifications.types.{$enumType}.entity_type")
                    : null;
                $priority = $enumType
                    ? config("notifications.types.{$enumType}.priority", 'info')
                    : 'info';

                DB::table('notification_reads')
                    ->where('id', $row->id)
                    ->update([
                        'type' => $enumType,
                        'priority' => $priority,
                        'status' => $row->read_at ? 'resolved' : 'active',
                        'entity_type' => $entityType,
                        'entity_id' => $row->notification_id,
                    ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_reads', function (Blueprint $table) {
            if (Schema::hasColumn('notification_reads', 'created_by')) {
                $table->dropForeign(['created_by']);
            }
            if (Schema::hasColumn('notification_reads', 'resolved_by')) {
                $table->dropForeign(['resolved_by']);
            }

            $this->dropIndexIfExists($table, 'notif_reads_user_status_idx');
            $this->dropIndexIfExists($table, 'notif_reads_type_status_idx');
            $this->dropIndexIfExists($table, 'notif_reads_priority_status_idx');

            $columns = [
                'type',
                'priority',
                'status',
                'audience',
                'entity_type',
                'entity_id',
                'created_by',
                'resolved_at',
                'resolved_by',
                'metadata',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('notification_reads', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addIndexIfMissing(Blueprint $table, array $columns, string $name): void
    {
        if ($this->indexExists($name)) {
            return;
        }

        $table->index($columns, $name);
    }

    private function dropIndexIfExists(Blueprint $table, string $name): void
    {
        if ($this->indexExists($name)) {
            $table->dropIndex($name);
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
