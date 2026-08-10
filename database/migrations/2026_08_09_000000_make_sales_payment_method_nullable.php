<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->enum('payment_method', [
                'cash',
                'card',
                'bank_transfer',
                'check',
                'orange_money',
                'wave',
            ])->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->enum('payment_method', [
                'cash',
                'card',
                'bank_transfer',
                'check',
                'orange_money',
                'wave',
            ])->default('cash')->nullable(false)->change();
        });
    }
};
