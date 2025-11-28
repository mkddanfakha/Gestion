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
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->onDelete('cascade'); // Bon de commande
            $table->foreignId('product_id')->constrained()->onDelete('cascade'); // Produit
            $table->integer('quantity'); // Quantité commandée
            $table->decimal('unit_price', 10, 2); // Prix unitaire
            $table->decimal('total_price', 10, 2); // Prix total
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
