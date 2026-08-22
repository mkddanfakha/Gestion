<?php

namespace App\Enums;

enum InventoryScopeType: string
{
    case Complete = 'complete';
    case Category = 'category';
    case StockPositive = 'stock_positive';

    public function isComplete(): bool
    {
        return $this === self::Complete;
    }

    public function isCategory(): bool
    {
        return $this === self::Category;
    }

    public function isStockPositive(): bool
    {
        return $this === self::StockPositive;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
