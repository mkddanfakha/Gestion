<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('form_type', 64);
            $table->string('mode', 16);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('data');
            $table->unsignedInteger('version')->default(1);
            $table->string('instance_id', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['user_id', 'form_type', 'mode', 'entity_id'], 'form_drafts_unique_scope');
            $table->index(['user_id', 'expires_at']);
            $table->index('form_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_drafts');
    }
};
