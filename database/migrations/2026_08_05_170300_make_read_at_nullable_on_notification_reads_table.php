<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * read_at nullable : NULL = notification active non lue, renseigné = lue / dismissée.
     */
    public function up(): void
    {
        Schema::table('notification_reads', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('notification_reads', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable(false)->change();
        });
    }
};
