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
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Nom unique de la permission (ex: "products.create")
            $table->string('resource', 50); // Ressource concernée (ex: "products", "customers")
            $table->string('action', 50); // Action (ex: "create", "edit", "update", "delete", "view")
            $table->text('description')->nullable(); // Description de la permission
            $table->timestamps();
            
            // Index unique pour éviter les doublons (avec longueur limitée)
            $table->unique(['resource', 'action'], 'permissions_resource_action_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
