<?php

namespace App\Services\Treasury;

use App\Models\PaymentTransaction;

/**
 * Normalisation des moyens de paiement ventes / dépenses pour agrégats trésorerie.
 *
 * Correspondances explicites (alias → canonique) :
 * - credit_card → card (carte bancaire côté dépenses)
 *
 * Canoniques reconnus sans alias :
 * - cash, card, bank_transfer, check, orange_money, wave (ventes, PaymentTransaction::PAYMENT_METHODS)
 * - mobile_money (dépenses uniquement ; distinct d'orange_money et wave)
 *
 * mobile_money n'est jamais fusionné avec orange_money ni wave.
 * Toute autre valeur reste en canonical = raw avec known = false.
 */
final class PaymentMethodNormalizer
{
    /** @var list<string> */
    public const CANONICAL_METHODS = [
        'cash',
        'card',
        'bank_transfer',
        'check',
        'orange_money',
        'wave',
        'mobile_money',
    ];

    /** @var array<string, string> raw => canonical */
    private const ALIASES = [
        'credit_card' => 'card',
    ];

    public function normalize(?string $raw): NormalizedPaymentMethod
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return new NormalizedPaymentMethod('', 'unknown', false);
        }

        if (isset(self::ALIASES[$raw])) {
            return new NormalizedPaymentMethod($raw, self::ALIASES[$raw], true);
        }

        if (in_array($raw, self::CANONICAL_METHODS, true)) {
            return new NormalizedPaymentMethod($raw, $raw, true);
        }

        if (in_array($raw, PaymentTransaction::PAYMENT_METHODS, true)) {
            return new NormalizedPaymentMethod($raw, $raw, true);
        }

        return new NormalizedPaymentMethod($raw, $raw, false);
    }

    /**
     * Valeurs brutes en base susceptibles de produire le canonique demandé (filtres SQL).
     *
     * @return list<string>
     */
    public function rawValuesMatchingCanonical(string $canonical): array
    {
        $canonical = trim($canonical);

        if ($canonical === '') {
            return [];
        }

        $rawValues = [$canonical];

        foreach (self::ALIASES as $raw => $mapped) {
            if ($mapped === $canonical) {
                $rawValues[] = $raw;
            }
        }

        return array_values(array_unique($rawValues));
    }
}
