<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('payment_transactions table has ledger columns without physical FK to users', function () {
    expect(Schema::hasTable('payment_transactions'))->toBeTrue();

    foreach ([
        'payable_type',
        'payable_id',
        'amount',
        'paid_at',
        'payment_method',
        'reference',
        'note',
        'origin',
        'user_id',
        'status',
        'voided_at',
        'voided_by_user_id',
        'void_reason',
        'idempotency_key',
    ] as $column) {
        expect(Schema::hasColumn('payment_transactions', $column))->toBeTrue($column.' missing');
    }

    $userForeignKeys = collect(DB::select('PRAGMA foreign_key_list(payment_transactions)'))
        ->filter(fn ($row) => in_array($row->from ?? '', ['user_id', 'voided_by_user_id'], true));

    expect($userForeignKeys)->toBeEmpty();
});

test('payment_transactions indexes include payable ledger and user lookups', function () {
    $indexes = collect(DB::select('PRAGMA index_list(payment_transactions)'))
        ->pluck('name')
        ->all();

    $indexInfos = collect($indexes)->map(function (string $indexName) {
        return collect(DB::select('PRAGMA index_info('.$indexName.')'))
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
    });

    $hasCompositePayable = $indexInfos->contains(
        fn (array $cols) => $cols === ['payable_type', 'payable_id', 'status'],
    );
    expect($hasCompositePayable)->toBeTrue();

    $hasPaidAt = $indexInfos->contains(fn (array $cols) => $cols === ['paid_at']);
    expect($hasPaidAt)->toBeTrue();

    $hasUserId = $indexInfos->contains(fn (array $cols) => $cols === ['user_id']);
    expect($hasUserId)->toBeTrue();

    $hasVoidedBy = $indexInfos->contains(fn (array $cols) => $cols === ['voided_by_user_id']);
    expect($hasVoidedBy)->toBeTrue();

    $hasIdempotencyUnique = collect(DB::select('PRAGMA index_list(payment_transactions)'))
        ->first(function ($row) {
            if (($row->unique ?? 0) !== 1) {
                return false;
            }
            $cols = collect(DB::select('PRAGMA index_info('.$row->name.')'))
                ->pluck('name')
                ->filter()
                ->values()
                ->all();

            return $cols === ['idempotency_key'];
        });

    expect($hasIdempotencyUnique)->not->toBeNull();
});
