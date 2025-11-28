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
        Schema::create('delivery_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_note_id')->constrained()->onDelete('cascade'); // Bon de livraison
            $table->foreignId('product_id')->constrained()->onDelete('cascade'); // Produit
            $table->integer('quantity'); // Quantité livrée
            $table->decimal('unit_price', 10, 2); // Prix unitaire d'achat
            $table->decimal('total_price', 10, 2); // Prix total
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_note_items');
    }
};
