<?php

namespace App\Exceptions;

use Exception;

class InsufficientStockException extends Exception
{
    public function __construct(
        public readonly int $productId,
        public readonly int $storeId,
        public readonly int $requestedQuantity,
        public readonly int $availableQuantity,
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf(
            'Stock insuffisant pour le produit #%d (magasin #%d) : demandé %d, disponible %d.',
            $productId,
            $storeId,
            $requestedQuantity,
            $availableQuantity,
        ));
    }
}
