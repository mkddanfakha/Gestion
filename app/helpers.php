<?php

use App\Support\CurrencyFormatter;

if (! function_exists('format_currency')) {
    function format_currency(float|int|string|null $amount): string
    {
        return CurrencyFormatter::format($amount);
    }
}
