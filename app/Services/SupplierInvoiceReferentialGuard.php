<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;

/**
 * Garde-fous applicatifs pour les références factures fournisseurs (schéma sans FK SQL).
 */
final class SupplierInvoiceReferentialGuard
{
    public static function blockingMessageForSupplierDeletion(Supplier $supplier): ?string
    {
        $count = SupplierInvoice::query()
            ->where('supplier_id', $supplier->id)
            ->count();

        if ($count === 0) {
            return null;
        }

        return "Impossible de supprimer ce fournisseur car {$count} facture(s) fournisseur lui sont associée(s).";
    }

    public static function blockingMessageForPurchaseOrderDeletion(int $purchaseOrderId): ?string
    {
        $count = SupplierInvoice::query()
            ->where('purchase_order_id', $purchaseOrderId)
            ->count();

        if ($count === 0) {
            return null;
        }

        return "Impossible de supprimer ce bon de commande car {$count} facture(s) fournisseur y font référence.";
    }

    public static function blockingMessageForDeliveryNoteDeletion(int $deliveryNoteId): ?string
    {
        $count = SupplierInvoice::query()
            ->where('delivery_note_id', $deliveryNoteId)
            ->count();

        if ($count === 0) {
            return null;
        }

        return "Impossible de supprimer ce bon de livraison car {$count} facture(s) fournisseur y font référence.";
    }

    public static function blockingMessageForUserDeletion(User $user): ?string
    {
        $count = SupplierInvoice::query()
            ->where('user_id', $user->id)
            ->count();

        if ($count === 0) {
            return null;
        }

        return "Impossible de supprimer cet utilisateur car {$count} facture(s) fournisseur sont rattachées à son compte.";
    }
}
