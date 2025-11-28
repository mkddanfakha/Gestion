<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Supprimer temporairement la contrainte unique
        DB::statement('ALTER TABLE products DROP INDEX products_sku_unique');
        
        // Rendre le champ sku nullable
        DB::statement('ALTER TABLE products MODIFY sku VARCHAR(6) NULL');
        
        // Recréer la contrainte unique
        DB::statement('ALTER TABLE products ADD UNIQUE KEY products_sku_unique (sku)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP INDEX products_sku_unique');
        DB::statement('ALTER TABLE products MODIFY sku VARCHAR(6) NOT NULL');
        DB::statement('ALTER TABLE products ADD UNIQUE KEY products_sku_unique (sku)');
    }
};
