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
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('payable_type', 32);
            $table->unsignedBigInteger('payable_id');
            $table->decimal('amount', 12, 2);
            $table->dateTime('paid_at');
            $table->string('payment_method', 32);
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->string('origin', 32)->default('recorded');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('posted');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->timestamps();

            $table->index(['payable_type', 'payable_id', 'status']);
            $table->index(['paid_at']);
            $table->index(['user_id']);
            $table->unique('idempotency_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
