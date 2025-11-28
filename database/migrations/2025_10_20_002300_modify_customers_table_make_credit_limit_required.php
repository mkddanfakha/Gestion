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
        Schema::table('customers', function (Blueprint $table) {
            // D'abord, mettre à jour les valeurs NULL existantes
            DB::statement('UPDATE customers SET credit_limit = 0 WHERE credit_limit IS NULL');
            
            // Ensuite, modifier la colonne pour qu'elle ne soit plus nullable
            $table->decimal('credit_limit', 10, 2)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('credit_limit', 10, 2)->nullable()->change();
        });
    }
};
