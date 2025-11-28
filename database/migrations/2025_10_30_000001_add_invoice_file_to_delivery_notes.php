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
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->string('invoice_file_path')->nullable()->after('invoice_number');
            $table->string('invoice_file_name')->nullable()->after('invoice_file_path');
            $table->string('invoice_file_mime')->nullable()->after('invoice_file_name');
            $table->unsignedBigInteger('invoice_file_size')->nullable()->after('invoice_file_mime');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_file_path',
                'invoice_file_name',
                'invoice_file_mime',
                'invoice_file_size',
            ]);
        });
    }
};


