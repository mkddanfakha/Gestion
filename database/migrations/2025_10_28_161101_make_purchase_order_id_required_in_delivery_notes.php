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
        Schema::table('delivery_notes', function (Blueprint $table) {
            // Supprimer l'ancienne contrainte
            $table->dropForeign(['purchase_order_id']);
            
            // Modifier la colonne pour la rendre obligatoire
            $table->foreignId('purchase_order_id')->nullable(false)->change();
            
            // Recréer la contrainte avec CASCADE DELETE
            $table->foreign('purchase_order_id')
                  ->references('id')
                  ->on('purchase_orders')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            // Supprimer la contrainte
            $table->dropForeign(['purchase_order_id']);
            
            // Restaurer nullable
            $table->foreignId('purchase_order_id')->nullable()->change();
            
            // Recréer la contrainte avec set null
            $table->foreign('purchase_order_id')
                  ->references('id')
                  ->on('purchase_orders')
                  ->onDelete('set null');
        });
    }
};
