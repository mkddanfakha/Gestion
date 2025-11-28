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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_number')->unique(); // Numéro unique de dépense
            $table->string('title'); // Titre de la dépense
            $table->text('description')->nullable(); // Description détaillée
            $table->decimal('amount', 10, 2); // Montant de la dépense
            $table->enum('category', [
                'fournitures', 'equipement', 'marketing', 'transport', 
                'formation', 'maintenance', 'utilities', 'autres'
            ]); // Catégorie de dépense
            $table->enum('payment_method', [
                'cash', 'bank_transfer', 'credit_card', 'mobile_money', 
                'orange_money', 'wave', 'check'
            ]); // Méthode de paiement
            $table->date('expense_date'); // Date de la dépense
            $table->string('receipt_number')->nullable(); // Numéro de reçu/facture
            $table->string('vendor')->nullable(); // Fournisseur/vendeur
            $table->text('notes')->nullable(); // Notes additionnelles
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Utilisateur qui a créé la dépense
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
