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
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_number')->unique(); // Numéro unique du bon de livraison
            $table->foreignId('purchase_order_id')->nullable()->constrained()->onDelete('set null'); // Lien vers le bon de commande
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade'); // Fournisseur
            $table->date('delivery_date'); // Date de livraison
            $table->enum('status', ['pending', 'validated', 'cancelled'])->default('pending'); // Statut
            $table->decimal('subtotal', 12, 2)->default(0); // Sous-total
            $table->decimal('tax_amount', 12, 2)->default(0); // Montant des taxes
            $table->decimal('discount_amount', 12, 2)->default(0); // Montant de la remise
            $table->decimal('total_amount', 12, 2)->default(0); // Montant total
            $table->text('notes')->nullable(); // Notes
            $table->string('invoice_number')->nullable(); // Numéro de facture fournisseur
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Utilisateur qui a créé
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_notes');
    }
};
