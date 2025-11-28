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
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Nom du fournisseur
            $table->string('contact_person')->nullable(); // Personne de contact
            $table->string('email')->nullable(); // Email
            $table->string('phone')->nullable(); // Téléphone
            $table->string('mobile')->nullable(); // Mobile
            $table->text('address')->nullable(); // Adresse
            $table->string('city')->nullable(); // Ville
            $table->string('country')->nullable(); // Pays
            $table->string('tax_id')->nullable(); // Numéro d'identification fiscale
            $table->text('notes')->nullable(); // Notes additionnelles
            $table->enum('status', ['active', 'inactive'])->default('active'); // Statut du fournisseur
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
