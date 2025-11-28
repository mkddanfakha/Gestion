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
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number')->unique(); // Numéro unique du bon de commande
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade'); // Fournisseur
            $table->date('order_date'); // Date de commande
            $table->date('expected_delivery_date')->nullable(); // Date de livraison prévue
            $table->enum('status', ['draft', 'sent', 'confirmed', 'partially_received', 'received', 'cancelled'])->default('draft'); // Statut
            $table->decimal('subtotal', 12, 2)->default(0); // Sous-total
            $table->decimal('tax_amount', 12, 2)->default(0); // Montant des taxes
            $table->decimal('discount_amount', 12, 2)->default(0); // Montant de la remise
            $table->decimal('total_amount', 12, 2)->default(0); // Montant total
            $table->text('notes')->nullable(); // Notes
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Utilisateur qui a créé
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
