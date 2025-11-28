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
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('sale_number')->unique(); // Numéro de vente unique
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Utilisateur qui a effectué la vente
            $table->decimal('subtotal', 10, 2); // Sous-total avant taxes
            $table->decimal('tax_amount', 10, 2)->default(0); // Montant des taxes
            $table->decimal('discount_amount', 10, 2)->default(0); // Montant de la remise
            $table->decimal('total_amount', 10, 2); // Montant total
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('completed');
            $table->enum('payment_method', ['cash', 'card', 'bank_transfer', 'check'])->default('cash');
            $table->text('notes')->nullable();
            $table->timestamp('sale_date')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
