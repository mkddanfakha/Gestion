<?php

namespace App\Services\Treasury;

/**
 * Rapport trésorerie cohérent (mouvements + totaux calculés une seule fois).
 */
final readonly class TreasuryReport
{
    public const DISCLAIMER = 'Les montants représentent les mouvements enregistrés dans Gestion. '
        .'Le net calculé ne constitue pas un solde réel certifié de caisse ou de compte bancaire. '
        .'Les reprises historiques sont identifiées séparément.';

    /**
     * @param  list<TreasuryMovement>  $movements
     * @param  array<string, array{inflows: float, outflows: float, net: float, known: bool}>  $totalsByPaymentMethod
     */
    public function __construct(
        public array $movements,
        public TreasuryPeriodTotals $periodTotals,
        public array $totalsByPaymentMethod,
    ) {}
}
