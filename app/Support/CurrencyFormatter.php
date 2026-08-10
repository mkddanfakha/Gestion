<?php

namespace App\Support;

class CurrencyFormatter
{
    public static function format(float|int|string|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return '0 FCFA';
        }

        $value = (float) $amount;

        if (! is_finite($value)) {
            return '0 FCFA';
        }

        return number_format((int) round($value), 0, ',', ' ') . ' FCFA';
    }
}
