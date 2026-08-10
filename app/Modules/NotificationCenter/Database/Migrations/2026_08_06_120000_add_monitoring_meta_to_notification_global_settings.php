<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_global_settings', function (Blueprint $table) {
            $table->json('monitoring_meta')->nullable()->after('realtime_meta');
        });
    }

    public function down(): void
    {
        Schema::table('notification_global_settings', function (Blueprint $table) {
            $table->dropColumn('monitoring_meta');
        });
    }
};
