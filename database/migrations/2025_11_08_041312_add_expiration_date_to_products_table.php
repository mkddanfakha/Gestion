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
        Schema::table('products', function (Blueprint $table) {
            $table->date('expiration_date')->nullable()->after('stock_quantity')->comment('Date d\'expiration du produit');
            $table->integer('alert_threshold_value')->nullable()->after('expiration_date')->comment('Valeur du seuil d\'alerte (nombre)');
            $table->enum('alert_threshold_unit', ['days', 'weeks', 'months'])->nullable()->after('alert_threshold_value')->comment('Unité du seuil d\'alerte (jours, semaines, mois)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['expiration_date', 'alert_threshold_value', 'alert_threshold_unit']);
        });
    }
};
