<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_drafts', function (Blueprint $table) {
            $table->string('scope_context', 128)->default('')->after('entity_id');
        });

        Schema::table('form_drafts', function (Blueprint $table) {
            $table->dropUnique('form_drafts_unique_scope');
        });

        Schema::table('form_drafts', function (Blueprint $table) {
            $table->unique(
                ['user_id', 'form_type', 'mode', 'entity_id', 'scope_context'],
                'form_drafts_unique_scope',
            );
        });
    }

    public function down(): void
    {
        Schema::table('form_drafts', function (Blueprint $table) {
            $table->dropUnique('form_drafts_unique_scope');
        });

        Schema::table('form_drafts', function (Blueprint $table) {
            $table->unique(['user_id', 'form_type', 'mode', 'entity_id'], 'form_drafts_unique_scope');
            $table->dropColumn('scope_context');
        });
    }
};
