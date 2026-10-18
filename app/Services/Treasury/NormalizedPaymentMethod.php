<?php

namespace App\Services\Treasury;

/**
 * Moyen de paiement normalisé pour le reporting trésorerie (lecture seule).
 *
 * Les valeurs en base ne sont jamais modifiées ; seul le libellé canonique sert aux agrégats.
 */
final readonly class NormalizedPaymentMethod
{
    public function __construct(
        public string $raw,
        public string $canonical,
        public bool $known,
    ) {}
}
