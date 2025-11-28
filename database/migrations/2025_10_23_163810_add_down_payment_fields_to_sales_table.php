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
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('down_payment_amount', 10, 2)->default(0)->after('discount_amount');
            $table->decimal('remaining_amount', 10, 2)->default(0)->after('down_payment_amount');
            $table->enum('payment_status', ['paid', 'partial', 'pending'])->default('paid')->after('remaining_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['down_payment_amount', 'remaining_amount', 'payment_status']);
        });
    }
};
