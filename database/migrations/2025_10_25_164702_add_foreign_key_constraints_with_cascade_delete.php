<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vérifie si une contrainte FK existe réellement dans la base.
     */
    private function foreignKeyExists(string $table, string $column): bool
{
    $driver = DB::getDriverName();

    if ($driver === 'mysql') {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }

    if ($driver === 'sqlite') {
        return collect(DB::select("PRAGMA foreign_key_list(\"{$table}\")"))
            ->contains(function ($foreignKey) use ($column) {
                return $foreignKey->from === $column;
            });
    }

    return false;
}

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /*
         * Cette migration harmonise les actions de suppression des FK.
         *
         * Les migrations précédentes créent déjà certaines FK. On vérifie
         * donc leur existence avant de les supprimer/recréer afin de pouvoir
         * gérer aussi une installation ayant subi une exécution partielle.
         */

        // 1. sale_items -> sales : CASCADE
        if ($this->foreignKeyExists('sale_items', 'sale_id')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->dropForeign(['sale_id']);
            });
        }

        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreign('sale_id')
                ->references('id')
                ->on('sales')
                ->cascadeOnDelete();
        });

        // 2. sale_items -> products : CASCADE
        if ($this->foreignKeyExists('sale_items', 'product_id')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->dropForeign(['product_id']);
            });
        }

        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();
        });

        // 3. products -> categories : CASCADE
        //
        // category_id est NOT NULL : SET NULL serait donc impossible.
        if ($this->foreignKeyExists('products', 'category_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropForeign(['category_id']);
            });
        }

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->cascadeOnDelete();
        });

        // 4. sales -> customers : SET NULL
        //
        // customer_id est nullable : SET NULL est approprié.
        if ($this->foreignKeyExists('sales', 'customer_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropForeign(['customer_id']);
            });
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->nullOnDelete();
        });

        // 5. sales -> users : CASCADE
        //
        // user_id est NOT NULL : SET NULL serait impossible.
        if ($this->foreignKeyExists('sales', 'user_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Supprimer les contraintes si elles existent.
        foreach ([
            ['sale_items', 'sale_id'],
            ['sale_items', 'product_id'],
            ['products', 'category_id'],
            ['sales', 'customer_id'],
            ['sales', 'user_id'],
        ] as [$tableName, $column]) {
            if ($this->foreignKeyExists($tableName, $column)) {
                Schema::table($tableName, function (Blueprint $table) use ($column) {
                    $table->dropForeign([$column]);
                });
            }
        }

        // Restaurer les contraintes sans action DELETE explicite.
        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreign('sale_id')
                ->references('id')
                ->on('sales');

            $table->foreign('product_id')
                ->references('id')
                ->on('products');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('category_id')
                ->references('id')
                ->on('categories');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreign('customer_id')
                ->references('id')
                ->on('customers');

            $table->foreign('user_id')
                ->references('id')
                ->on('users');
        });
    }
};