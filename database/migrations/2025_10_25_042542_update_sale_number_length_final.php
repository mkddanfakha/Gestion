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
            // Supprimer d'abord la contrainte unique existante
            $table->dropUnique(['sale_number']);
        });
        
        Schema::table('sales', function (Blueprint $table) {
            // Modifier la longueur du champ sale_number pour accommoder le nouveau format (8 caractères)
            $table->string('sale_number', 8)->change();
        });
        
        Schema::table('sales', function (Blueprint $table) {
            // Recréer la contrainte unique
            $table->unique('sale_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Revenir à la longueur par défaut (255 caractères)
            $table->string('sale_number')->unique()->change();
        });
    }
};
