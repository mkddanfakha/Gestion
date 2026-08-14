<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('signature_path')->nullable()->after('logo_path');
            $table->string('stamp_path')->nullable()->after('signature_path');

            $table->boolean('print_signature_on_invoice')->default(true)->after('stamp_path');
            $table->boolean('print_stamp_on_invoice')->default(true)->after('print_signature_on_invoice');
            $table->boolean('print_signature_on_quote')->default(true)->after('print_stamp_on_invoice');
            $table->boolean('print_stamp_on_quote')->default(true)->after('print_signature_on_quote');
            $table->boolean('print_signature_on_purchase_order')->default(false)->after('print_stamp_on_quote');
            $table->boolean('print_stamp_on_purchase_order')->default(false)->after('print_signature_on_purchase_order');
            $table->boolean('print_signature_on_delivery_note')->default(false)->after('print_stamp_on_purchase_order');
            $table->boolean('print_stamp_on_delivery_note')->default(false)->after('print_signature_on_delivery_note');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'signature_path',
                'stamp_path',
                'print_signature_on_invoice',
                'print_stamp_on_invoice',
                'print_signature_on_quote',
                'print_stamp_on_quote',
                'print_signature_on_purchase_order',
                'print_stamp_on_purchase_order',
                'print_signature_on_delivery_note',
                'print_stamp_on_delivery_note',
            ]);
        });
    }
};
