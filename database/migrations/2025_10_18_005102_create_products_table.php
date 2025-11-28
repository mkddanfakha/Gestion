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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('sku')->unique(); // Code produit unique
            $table->string('barcode')->nullable(); // Code-barres
            $table->decimal('price', 10, 2); // Prix de vente
            $table->decimal('cost_price', 10, 2)->nullable(); // Prix d'achat
            $table->integer('stock_quantity')->default(0); // Quantité en stock
            $table->integer('min_stock_level')->default(0); // Niveau minimum de stock
            $table->string('unit')->default('pièce'); // Unité (pièce, kg, litre, etc.)
            $table->string('image')->nullable(); // Image du produit
            $table->boolean('is_active')->default(true); // Produit actif/inactif
            $table->foreignId('category_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
