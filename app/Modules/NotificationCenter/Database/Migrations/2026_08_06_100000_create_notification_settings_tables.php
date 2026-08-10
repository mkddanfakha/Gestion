<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_global_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->boolean('realtime_enabled')->default(true);
            $table->boolean('browser_enabled')->default(false);
            $table->boolean('sound_enabled')->default(false);
            $table->boolean('toasts_enabled')->default(true);
            $table->boolean('badge_enabled')->default(true);
            $table->boolean('grouping_enabled')->default(true);
            $table->boolean('auto_mark_read_on_open')->default(false);
            $table->string('default_sound')->default('classic');
            $table->unsignedSmallInteger('maintenance_cleanup_days')->default(90);
            $table->json('realtime_meta')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_type_settings', function (Blueprint $table) {
            $table->string('type')->primary();
            $table->string('priority')->default('info');
            $table->json('recipients');
            $table->json('channels');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('toasts_enabled')->default(true);
            $table->boolean('sound_enabled')->default(true);
            $table->boolean('browser_enabled')->default(false);
            $table->boolean('critical_only')->default(false);
            $table->boolean('hide_resolved')->default(true);
            $table->string('sound_profile')->default('classic');
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_preferences');
        Schema::dropIfExists('notification_type_settings');
        Schema::dropIfExists('notification_global_settings');
    }
};
