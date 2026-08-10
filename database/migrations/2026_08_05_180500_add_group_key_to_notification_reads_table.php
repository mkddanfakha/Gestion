<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_reads', function (Blueprint $table) {
            if (! Schema::hasColumn('notification_reads', 'group_key')) {
                $table->string('group_key', 100)->nullable()->after('entity_id');
                $table->index(['user_id', 'type', 'group_key', 'status'], 'notif_reads_user_group_status_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_reads', function (Blueprint $table) {
            if (Schema::hasColumn('notification_reads', 'group_key')) {
                $table->dropIndex('notif_reads_user_group_status_idx');
                $table->dropColumn('group_key');
            }
        });
    }
};
