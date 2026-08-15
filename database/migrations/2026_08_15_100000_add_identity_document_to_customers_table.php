<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('identity_document_type', 20)->nullable()->after('phone');
            $table->string('identity_document_number', 50)->nullable()->after('identity_document_type');
            $table->string('identity_document_number_normalized', 50)->nullable()->after('identity_document_number');
            $table->string('phone_normalized', 20)->nullable()->after('identity_document_number_normalized');

            $table->unique(
                ['identity_document_type', 'identity_document_number_normalized'],
                'customers_identity_document_unique',
            );
            $table->index('identity_document_number_normalized', 'customers_identity_number_norm_index');
            $table->index('phone_normalized', 'customers_phone_normalized_index');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_identity_document_unique');
            $table->dropIndex('customers_identity_number_norm_index');
            $table->dropIndex('customers_phone_normalized_index');
            $table->dropColumn([
                'identity_document_type',
                'identity_document_number',
                'identity_document_number_normalized',
                'phone_normalized',
            ]);
        });
    }
};
