<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('empty sqlite migrate creates expected tables and products category FK', function () {
    $required = [
        'users',
        'companies',
        'customers',
        'categories',
        'products',
        'sales',
        'sale_items',
        'quotes',
        'quote_items',
        'expenses',
        'suppliers',
        'purchase_orders',
        'purchase_order_items',
        'delivery_notes',
        'delivery_note_items',
        'sessions',
        'jobs',
        'failed_jobs',
        'permissions',
        'media',
    ];

    foreach ($required as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table.' missing');
    }

    expect(Schema::hasColumn('products', 'category_id'))->toBeTrue();

    $fk = collect(DB::select('PRAGMA foreign_key_list(products)'))
        ->first(fn ($row) => ($row->from ?? null) === 'category_id');

    expect($fk)->not->toBeNull();
    expect($fk->table)->toBe('categories');
    expect($fk->to)->toBe('id');
});
