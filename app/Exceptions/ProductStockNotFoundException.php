<?php

namespace App\Exceptions;

use Exception;

class ProductStockNotFoundException extends Exception
{
    public function __construct(
        public readonly int $productId,
        public readonly int $storeId,
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf(
            'Aucune ligne de stock pour le produit #%d dans le magasin #%d.',
            $productId,
            $storeId,
        ));
    }
}
