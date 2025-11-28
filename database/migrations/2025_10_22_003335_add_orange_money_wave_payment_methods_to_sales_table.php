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
        Schema::table('sales', function (Blueprint $table) {
            // Modifier l'enum pour ajouter les nouveaux modes de paiement
            $table->enum('payment_method', [
                'cash', 
                'card', 
                'bank_transfer', 
                'check', 
                'orange_money', 
                'wave'
            ])->default('cash')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Revenir aux modes de paiement originaux
            $table->enum('payment_method', [
                'cash', 
                'card', 
                'bank_transfer', 
                'check'
            ])->default('cash')->change();
        });
    }
};
