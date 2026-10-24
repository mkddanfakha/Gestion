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
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            // NIANE / schéma MyISAM : pas de FK SQL vers suppliers, purchase_orders, delivery_notes ou users.
            $table->unsignedBigInteger('supplier_id');
            $table->string('invoice_number');
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->decimal('total_amount', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('remaining_amount', 12, 2);
            $table->enum('payment_status', ['pending', 'partial', 'paid'])->default('pending');
            $table->enum('status', ['draft', 'open', 'cancelled'])->default('draft');
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->unsignedBigInteger('delivery_note_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->unique(['supplier_id', 'invoice_number']);
            $table->index('supplier_id');
            $table->index('purchase_order_id');
            $table->index('delivery_note_id');
            $table->index('user_id');
            $table->index('due_date');
            $table->index('payment_status');
            $table->index('status');
            $table->index(['supplier_id', 'payment_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_invoices');
    }
};
