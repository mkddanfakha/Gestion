<?php

use App\Support\CurrencyFormatter;

test('format_currency helper formats FCFA amounts without decimals', function () {
    expect(format_currency(0))->toBe('0 FCFA');
    expect(format_currency(1))->toBe('1 FCFA');
    expect(format_currency(999))->toBe('999 FCFA');
    expect(format_currency(1000))->toBe('1 000 FCFA');
    expect(format_currency(75000))->toBe('75 000 FCFA');
    expect(format_currency(1250000))->toBe('1 250 000 FCFA');
    expect(format_currency(15000000))->toBe('15 000 000 FCFA');
});

test('currency formatter class matches helper', function () {
    expect(CurrencyFormatter::format(2450000))->toBe('2 450 000 FCFA');
});
