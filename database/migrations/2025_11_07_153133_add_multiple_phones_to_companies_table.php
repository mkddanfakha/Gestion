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
        Schema::table('companies', function (Blueprint $table) {
            // Renommer phone en phone1 pour conserver les données existantes
            $table->renameColumn('phone', 'phone1');
            // Ajouter phone2 et phone3
            $table->string('phone2')->nullable()->after('phone1');
            $table->string('phone3')->nullable()->after('phone2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->renameColumn('phone1', 'phone');
            $table->dropColumn(['phone2', 'phone3']);
        });
    }
};
