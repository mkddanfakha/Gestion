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
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('quote_number', 9)->unique(); // Numéro de devis unique (format: DEYYMMXXX)
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Utilisateur qui a créé le devis
            $table->decimal('subtotal', 10, 2); // Sous-total avant taxes
            $table->decimal('tax_amount', 10, 2)->default(0); // Montant des taxes
            $table->decimal('discount_amount', 10, 2)->default(0); // Montant de la remise
            $table->decimal('total_amount', 10, 2); // Montant total
            $table->enum('status', ['draft', 'sent', 'accepted', 'rejected', 'expired'])->default('draft');
            $table->date('valid_until')->nullable(); // Date de validité du devis
            $table->text('notes')->nullable();
            $table->timestamp('quote_date')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
