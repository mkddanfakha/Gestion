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
        Schema::create('notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('notification_type'); // 'sale_due_today', 'low_stock', 'expiring_product'
            $table->unsignedBigInteger('notification_id'); // ID de la vente, du produit, etc.
            $table->timestamp('read_at');
            $table->timestamps();

            // Index unique pour éviter les doublons (nom court pour MySQL)
            $table->unique(['user_id', 'notification_type', 'notification_id'], 'notif_reads_unique');
            $table->index(['user_id', 'notification_type'], 'notif_reads_user_type_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_reads');
    }
};
