<?php

namespace App\Services\Treasury;

use App\Auth\AuthorizationService;
use App\Enums\PermissionName;
use App\Models\User;

/**
 * Détermine le périmètre de données trésorerie selon RBAC et scopes Sale / Expense.
 */
final class TreasuryAccessPolicy
{
    public function __construct(
        private AuthorizationService $authorization,
    ) {}

    public function queryScopeFor(User $user): TreasuryQueryScope
    {
        $includeSalePayments = $user->isAdmin()
            || $this->authorization->any($user, [
                PermissionName::SalesView,
                PermissionName::SalesPaymentsView,
            ]);

        $includeExpenses = $user->isAdmin()
            || $this->authorization->allows($user, PermissionName::ExpensesView);

        $includeSupplierInvoicePayments = $user->isAdmin()
            || $this->authorization->any($user, [
                PermissionName::SupplierInvoicesView,
                PermissionName::SupplierInvoicesPaymentsView,
            ]);

        return new TreasuryQueryScope(
            user: $user,
            includeSalePayments: $includeSalePayments,
            includeExpenses: $includeExpenses,
            includeSupplierInvoicePayments: $includeSupplierInvoicePayments,
        );
    }
}
