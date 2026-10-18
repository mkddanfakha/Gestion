<?php

namespace App\Services\Treasury;

use Carbon\CarbonInterface;

/**
 * Filtre de période pour la lecture trésorerie.
 *
 * @param  string|null  $paymentMethodCanonical  Canonique PaymentMethodNormalizer (ex. card, cash)
 */
final readonly class TreasuryReadFilter
{
    public function __construct(
        public CarbonInterface $from,
        public CarbonInterface $to,
        public ?string $paymentMethodCanonical = null,
    ) {}
}
