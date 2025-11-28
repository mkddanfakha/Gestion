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
        Schema::table('notification_reads', function (Blueprint $table) {
            // Ajouter les index si la table existe déjà
            try {
                $table->unique(['user_id', 'notification_type', 'notification_id'], 'notif_reads_unique');
            } catch (\Exception $e) {
                // L'index existe peut-être déjà, on continue
            }
            
            try {
                $table->index(['user_id', 'notification_type'], 'notif_reads_user_type_idx');
            } catch (\Exception $e) {
                // L'index existe peut-être déjà, on continue
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_reads', function (Blueprint $table) {
            $table->dropUnique('notif_reads_unique');
            $table->dropIndex('notif_reads_user_type_idx');
        });
    }
};
