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
        // Vérifier si l'index existe avant de le supprimer
        $indexExists = DB::select("SHOW INDEX FROM products WHERE Key_name = 'products_sku_unique'");
        
        if (!empty($indexExists)) {
            DB::statement('ALTER TABLE products DROP INDEX products_sku_unique');
        }
        
        DB::statement('ALTER TABLE products MODIFY sku VARCHAR(6) NOT NULL');
        DB::statement('ALTER TABLE products ADD UNIQUE KEY products_sku_unique (sku)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Revenir à la longueur par défaut (255 caractères)
            $table->dropUnique(['sku']);
            $table->string('sku')->change();
            $table->unique('sku');
        });
    }
};
