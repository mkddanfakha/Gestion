<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notification_preferences', function (Blueprint $table) {
            $table->string('toast_position')->default('bottom-right')->after('sound_profile');
            $table->json('toast_durations')->nullable()->after('toast_position');
            $table->decimal('sound_volume', 4, 2)->default(0.15)->after('toast_durations');
            $table->json('sound_profiles')->nullable()->after('sound_volume');
            $table->boolean('auto_mark_read_on_open')->default(true)->after('sound_profiles');
            $table->boolean('grouping_enabled')->default(true)->after('auto_mark_read_on_open');
        });
    }

    public function down(): void
    {
        Schema::table('user_notification_preferences', function (Blueprint $table) {
            $table->dropColumn([
                'toast_position',
                'toast_durations',
                'sound_volume',
                'sound_profiles',
                'auto_mark_read_on_open',
                'grouping_enabled',
            ]);
        });
    }
};
