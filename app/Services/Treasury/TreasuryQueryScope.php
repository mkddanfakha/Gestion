<?php

namespace App\Services\Treasury;

use App\Models\User;

/**
 * Périmètre RBAC pour la lecture trésorerie (ventes, règlements fournisseurs, dépenses).
 */
final readonly class TreasuryQueryScope
{
    public function __construct(
        public User $user,
        public bool $includeSalePayments,
        public bool $includeExpenses,
        public bool $includeSupplierInvoicePayments = true,
    ) {}
}
