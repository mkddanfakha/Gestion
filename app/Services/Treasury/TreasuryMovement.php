<?php

namespace App\Services\Treasury;

use Carbon\CarbonInterface;

/**
 * Mouvement de trésorerie en lecture seule (entrée ou sortie).
 */
final readonly class TreasuryMovement
{
    public const SOURCE_SALE_PAYMENT = 'sale_payment';

    public const SOURCE_SUPPLIER_INVOICE_PAYMENT = 'supplier_invoice_payment';

    public const SOURCE_EXPENSE = 'expense';

    public function __construct(
        public string $movementKey,
        public string $sourceType,
        public int $sourceId,
        public CarbonInterface $occurredAt,
        public string $label,
        public string $paymentMethodRaw,
        public string $paymentMethodCanonical,
        public bool $paymentMethodKnown,
        public float $amount,
        public ?string $paymentOrigin,
        public bool $isHistoricalBackfill,
        public ?int $saleId,
        public ?int $supplierInvoiceId = null,
        public ?string $supplierInvoiceNumber = null,
        public ?string $supplierName = null,
    ) {}
}
