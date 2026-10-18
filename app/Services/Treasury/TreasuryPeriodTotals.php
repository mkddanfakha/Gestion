<?php

namespace App\Services\Treasury;

/**
 * Totaux sur une période. Le net n'est pas un solde bancaire ou de caisse physique certifié.
 */
final readonly class TreasuryPeriodTotals
{
    public function __construct(
        public float $totalInflows,
        public float $totalOutflows,
        public float $net,
    ) {}
}
