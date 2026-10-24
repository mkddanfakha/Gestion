<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * @return list<array<int, string>>
 */
function supplierInvoicesIndexColumnSets(): array
{
    return collect(DB::select('PRAGMA index_list(supplier_invoices)'))
        ->pluck('name')
        ->map(function (string $indexName) {
            return collect(DB::select('PRAGMA index_info('.$indexName.')'))
                ->pluck('name')
                ->filter()
                ->values()
                ->all();
        })
        ->values()
        ->all();
}

test('supplier_invoices table has expected columns', function () {
    expect(Schema::hasTable('supplier_invoices'))->toBeTrue();

    foreach ([
        'supplier_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'total_amount',
        'paid_amount',
        'remaining_amount',
        'payment_status',
        'status',
        'purchase_order_id',
        'delivery_note_id',
        'notes',
        'user_id',
    ] as $column) {
        expect(Schema::hasColumn('supplier_invoices', $column))->toBeTrue($column.' missing');
    }
});

test('supplier_invoices has composite unique supplier_id and invoice_number', function () {
    $uniqueIndex = collect(DB::select('PRAGMA index_list(supplier_invoices)'))
        ->first(function ($row) {
            if (($row->unique ?? 0) !== 1) {
                return false;
            }

            $cols = collect(DB::select('PRAGMA index_info('.$row->name.')'))
                ->pluck('name')
                ->filter()
                ->values()
                ->all();

            return $cols === ['supplier_id', 'invoice_number'];
        });

    expect($uniqueIndex)->not->toBeNull();
});

test('supplier_invoices indexes include due_date payment_status and status', function () {
    $indexInfos = supplierInvoicesIndexColumnSets();

    expect(collect($indexInfos)->contains(fn (array $cols) => $cols === ['due_date']))->toBeTrue();
    expect(collect($indexInfos)->contains(fn (array $cols) => $cols === ['payment_status']))->toBeTrue();
    expect(collect($indexInfos)->contains(fn (array $cols) => $cols === ['status']))->toBeTrue();
});

test('supplier_invoices indexes include parent lookup columns without physical foreign keys', function () {
    $indexInfos = supplierInvoicesIndexColumnSets();

    foreach (['supplier_id', 'purchase_order_id', 'delivery_note_id', 'user_id'] as $column) {
        expect(collect($indexInfos)->contains(fn (array $cols) => $cols === [$column]))
            ->toBeTrue("missing index on {$column}");
    }

    expect(DB::select('PRAGMA foreign_key_list(supplier_invoices)'))->toBeEmpty();
});
