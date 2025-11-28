<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Mettre à jour tous les clients avec credit_limit = 0 vers une valeur minimale de 0.01
        DB::statement('UPDATE customers SET credit_limit = 0.01 WHERE credit_limit = 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remettre les valeurs à 0 (optionnel, car on ne peut pas vraiment "annuler" cette modification)
        DB::statement('UPDATE customers SET credit_limit = 0 WHERE credit_limit = 0.01');
    }
};
