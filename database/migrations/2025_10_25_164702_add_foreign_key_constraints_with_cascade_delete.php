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
        // Ajouter les contraintes de clé étrangère avec CASCADE DELETE pour éviter les données orphelines
        
        // 1. Contrainte pour sale_items -> sales
        Schema::table('sale_items', function (Blueprint $table) {
            // Supprimer d'abord l'index existant s'il existe
            $table->dropForeign(['sale_id']);
            
            // Recréer avec CASCADE DELETE
            $table->foreign('sale_id')
                  ->references('id')
                  ->on('sales')
                  ->onDelete('cascade');
        });
        
        // 2. Contrainte pour sale_items -> products
        Schema::table('sale_items', function (Blueprint $table) {
            // Supprimer d'abord l'index existant s'il existe
            $table->dropForeign(['product_id']);
            
            // Recréer avec CASCADE DELETE
            $table->foreign('product_id')
                  ->references('id')
                  ->on('products')
                  ->onDelete('cascade');
        });
        
        // 3. Contrainte pour products -> categories
        Schema::table('products', function (Blueprint $table) {
            // Supprimer d'abord l'index existant s'il existe
            $table->dropForeign(['category_id']);
            
            // Recréer avec SET NULL (on garde le produit mais on supprime la référence à la catégorie)
            $table->foreign('category_id')
                  ->references('id')
                  ->on('categories')
                  ->onDelete('set null');
        });
        
        // 4. Contrainte pour sales -> customers
        Schema::table('sales', function (Blueprint $table) {
            // Supprimer d'abord l'index existant s'il existe
            $table->dropForeign(['customer_id']);
            
            // Recréer avec SET NULL (on garde la vente mais on supprime la référence au client)
            $table->foreign('customer_id')
                  ->references('id')
                  ->on('customers')
                  ->onDelete('set null');
        });
        
        // 5. Contrainte pour sales -> users
        Schema::table('sales', function (Blueprint $table) {
            // Supprimer d'abord l'index existant s'il existe
            $table->dropForeign(['user_id']);
            
            // Recréer avec SET NULL (on garde la vente mais on supprime la référence à l'utilisateur)
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Supprimer toutes les contraintes de clé étrangère
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropForeign(['sale_id']);
            $table->dropForeign(['product_id']);
        });
        
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
        });
        
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['user_id']);
        });
        
        // Recréer les contraintes sans CASCADE DELETE
        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreign('sale_id')->references('id')->on('sales');
            $table->foreign('product_id')->references('id')->on('products');
        });
        
        Schema::table('products', function (Blueprint $table) {
            $table->foreign('category_id')->references('id')->on('categories');
        });
        
        Schema::table('sales', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('user_id')->references('id')->on('users');
        });
    }
};