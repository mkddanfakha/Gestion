<?php

namespace App\Services\UserActivity;

use App\Auth\AuthorizationService;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Expense;
use App\Models\InventorySession;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Détail JSON pour le modal « Activité des utilisateurs ».
 * Applique le même RBAC que les pages Show métier (pas de contournement via user-activities.view).
 */
class UserActivityDetailService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * @return array{
     *     type: string,
     *     type_label: string,
     *     fields: list<array{label: string, value: string|null}>,
     *     amounts: list<array{label: string, value: float|null}>,
     *     items: list<array{label: string, quantity: float|int|null, unit_price: float|null, total: float|null}>,
     *     notes: string|null,
     * }
     */
    public function detail(Request $request, string $type, int $id): array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new HttpException(401, 'Vous devez être connecté.');
        }

        if (! array_key_exists($type, UserActivityQueryService::activityTypes())) {
            throw new NotFoundHttpException('Type d\'activité inconnu.');
        }

        return match ($type) {
            UserActivityQueryService::TYPE_SALE => $this->saleDetail($user, $id),
            UserActivityQueryService::TYPE_EXPENSE => $this->expenseDetail($user, $id),
            UserActivityQueryService::TYPE_QUOTE => $this->quoteDetail($user, $id),
            UserActivityQueryService::TYPE_PURCHASE_ORDER => $this->purchaseOrderDetail($user, $id),
            UserActivityQueryService::TYPE_DELIVERY_NOTE => $this->deliveryNoteDetail($user, $id),
            UserActivityQueryService::TYPE_INVENTORY => $this->inventoryDetail($user, $id),
            default => throw new NotFoundHttpException('Type d\'activité inconnu.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function saleDetail(User $user, int $id): array
    {
        $this->assertCanView($user, 'sales.view');

        $sale = Sale::query()->with(['customer:id,name', 'user:id,name', 'saleItems.product:id,name'])->find($id);
        if (! $sale) {
            throw new NotFoundHttpException('Vente introuvable.');
        }

        if ($user->isVendeur() && $sale->user_id !== $user->id) {
            throw new HttpException(403, 'Accès refusé. Vous ne pouvez accéder qu\'à vos propres ventes.');
        }

        return $this->payload(
            UserActivityQueryService::TYPE_SALE,
            [
                ['label' => 'Référence', 'value' => $sale->sale_number],
                ['label' => 'Date', 'value' => $this->formatDateTime($sale->sale_date)],
                ['label' => 'Client', 'value' => $sale->customer?->name],
                ['label' => 'Utilisateur', 'value' => $sale->user?->name],
                ['label' => 'Statut', 'value' => $sale->status],
                ['label' => 'Paiement', 'value' => $sale->payment_status],
                ['label' => 'Mode de paiement', 'value' => $sale->payment_method],
            ],
            [
                ['label' => 'Sous-total', 'value' => $this->money($sale->subtotal)],
                ['label' => 'TVA', 'value' => $this->money($sale->tax_amount)],
                ['label' => 'Remise', 'value' => $this->money($sale->discount_amount)],
                ['label' => 'Total', 'value' => $this->money($sale->total_amount)],
            ],
            $sale->saleItems->map(fn ($item) => [
                'label' => (string) ($item->product?->name ?? ('Produit #'.$item->product_id)),
                'quantity' => $item->quantity,
                'unit_price' => $this->money($item->unit_price),
                'total' => $this->money($item->total_price),
            ])->values()->all(),
            $sale->notes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function expenseDetail(User $user, int $id): array
    {
        $this->assertCanView($user, 'expenses.view');

        $expense = Expense::query()->with(['user:id,name', 'supplier:id,name'])->find($id);
        if (! $expense) {
            throw new NotFoundHttpException('Dépense introuvable.');
        }

        if ($user->isGestionnaire() && $expense->user_id !== $user->id) {
            throw new HttpException(403, 'Accès refusé. Vous ne pouvez accéder qu\'à vos propres dépenses.');
        }

        return $this->payload(
            UserActivityQueryService::TYPE_EXPENSE,
            [
                ['label' => 'Référence', 'value' => $expense->expense_number],
                ['label' => 'Titre', 'value' => $expense->title],
                ['label' => 'Date', 'value' => $this->formatDate($expense->expense_date)],
                ['label' => 'Catégorie', 'value' => $expense->category],
                ['label' => 'Fournisseur', 'value' => $expense->supplier?->name ?? $expense->vendor],
                ['label' => 'Utilisateur', 'value' => $expense->user?->name],
                ['label' => 'Paiement', 'value' => $expense->payment_method],
                ['label' => 'N° reçu', 'value' => $expense->receipt_number],
            ],
            [
                ['label' => 'Montant', 'value' => $this->money($expense->amount)],
            ],
            [],
            $expense->description ?: $expense->notes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteDetail(User $user, int $id): array
    {
        $this->assertCanView($user, 'quotes.view');

        $quote = Quote::query()->with(['customer:id,name', 'user:id,name', 'quoteItems.product:id,name'])->find($id);
        if (! $quote) {
            throw new NotFoundHttpException('Devis introuvable.');
        }

        return $this->payload(
            UserActivityQueryService::TYPE_QUOTE,
            [
                ['label' => 'Référence', 'value' => $quote->quote_number],
                ['label' => 'Date', 'value' => $this->formatDateTime($quote->quote_date)],
                ['label' => 'Client', 'value' => $quote->customer?->name],
                ['label' => 'Utilisateur', 'value' => $quote->user?->name],
                ['label' => 'Statut', 'value' => $quote->status],
                ['label' => 'Validité', 'value' => $this->formatDate($quote->valid_until)],
            ],
            [
                ['label' => 'Montant HT', 'value' => $this->money($quote->subtotal)],
                ['label' => 'TVA', 'value' => $this->money($quote->tax_amount)],
                ['label' => 'Remise', 'value' => $this->money($quote->discount_amount)],
                ['label' => 'Montant TTC', 'value' => $this->money($quote->total_amount)],
            ],
            $quote->quoteItems->map(fn ($item) => [
                'label' => (string) ($item->product?->name ?? ('Produit #'.$item->product_id)),
                'quantity' => $item->quantity,
                'unit_price' => $this->money($item->unit_price),
                'total' => $this->money($item->total_price),
            ])->values()->all(),
            $quote->notes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseOrderDetail(User $user, int $id): array
    {
        $this->assertCanView($user, 'purchase-orders.view');

        $po = PurchaseOrder::query()->with(['supplier:id,name', 'user:id,name', 'items.product:id,name'])->find($id);
        if (! $po) {
            throw new NotFoundHttpException('Bon de commande introuvable.');
        }

        return $this->payload(
            UserActivityQueryService::TYPE_PURCHASE_ORDER,
            [
                ['label' => 'Référence', 'value' => $po->po_number],
                ['label' => 'Date commande', 'value' => $this->formatDate($po->order_date)],
                ['label' => 'Livraison prévue', 'value' => $this->formatDate($po->expected_delivery_date)],
                ['label' => 'Fournisseur', 'value' => $po->supplier?->name],
                ['label' => 'Utilisateur', 'value' => $po->user?->name],
                ['label' => 'Statut', 'value' => $po->status],
            ],
            [
                ['label' => 'Sous-total', 'value' => $this->money($po->subtotal)],
                ['label' => 'TVA', 'value' => $this->money($po->tax_amount)],
                ['label' => 'Remise', 'value' => $this->money($po->discount_amount)],
                ['label' => 'Total', 'value' => $this->money($po->total_amount)],
            ],
            $po->items->map(fn ($item) => [
                'label' => (string) ($item->product?->name ?? ('Produit #'.$item->product_id)),
                'quantity' => $item->quantity,
                'unit_price' => $this->money($item->unit_price),
                'total' => $this->money($item->total_price),
            ])->values()->all(),
            $po->notes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryNoteDetail(User $user, int $id): array
    {
        $this->assertCanView($user, 'delivery-notes.view');

        $note = DeliveryNote::query()
            ->with(['supplier:id,name', 'user:id,name', 'purchaseOrder:id,po_number', 'items.product:id,name'])
            ->find($id);
        if (! $note) {
            throw new NotFoundHttpException('Bon de livraison introuvable.');
        }

        return $this->payload(
            UserActivityQueryService::TYPE_DELIVERY_NOTE,
            [
                ['label' => 'Référence', 'value' => $note->delivery_number],
                ['label' => 'Date', 'value' => $this->formatDate($note->delivery_date)],
                ['label' => 'Fournisseur', 'value' => $note->supplier?->name],
                ['label' => 'BC lié', 'value' => $note->purchaseOrder?->po_number],
                ['label' => 'Utilisateur', 'value' => $note->user?->name],
                ['label' => 'Statut', 'value' => $note->status],
                ['label' => 'N° facture', 'value' => $note->invoice_number],
            ],
            [
                ['label' => 'Sous-total', 'value' => $this->money($note->subtotal)],
                ['label' => 'TVA', 'value' => $this->money($note->tax_amount)],
                ['label' => 'Remise', 'value' => $this->money($note->discount_amount)],
                ['label' => 'Total', 'value' => $this->money($note->total_amount)],
            ],
            $note->items->map(fn ($item) => [
                'label' => (string) ($item->product?->name ?? ('Produit #'.$item->product_id)),
                'quantity' => $item->quantity,
                'unit_price' => $this->money($item->unit_price),
                'total' => $this->money($item->total_price),
            ])->values()->all(),
            $note->notes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function inventoryDetail(User $user, int $id): array
    {
        $this->assertCanView($user, 'inventory.view');

        $session = InventorySession::query()
            ->with(['createdBy:id,name', 'store:id,name'])
            ->find($id);
        if (! $session) {
            throw new NotFoundHttpException('Inventaire introuvable.');
        }

        $company = Company::getInstance();
        if ($session->company_id !== $company->id) {
            throw new NotFoundHttpException('Inventaire introuvable.');
        }

        $status = $session->status instanceof \BackedEnum
            ? $session->status->value
            : (string) $session->status;

        $scopeType = $session->scope_type instanceof \BackedEnum
            ? $session->scope_type->value
            : (string) $session->scope_type;

        return $this->payload(
            UserActivityQueryService::TYPE_INVENTORY,
            [
                ['label' => 'Référence', 'value' => $session->reference],
                ['label' => 'Nom', 'value' => $session->name],
                ['label' => 'Date création', 'value' => $this->formatDateTime($session->created_at)],
                ['label' => 'Magasin', 'value' => $session->store?->name],
                ['label' => 'Périmètre', 'value' => $scopeType],
                ['label' => 'Utilisateur', 'value' => $session->createdBy?->name],
                ['label' => 'Statut', 'value' => $status],
            ],
            [],
            [],
            $session->description,
        );
    }

    private function assertCanView(User $user, string $permission): void
    {
        if (! $this->authorization->allows($user, $permission)) {
            throw new HttpException(403, 'Accès refusé. Vous n\'avez pas la permission de consulter ce document.');
        }
    }

    /**
     * @param  list<array{label: string, value: string|null}>  $fields
     * @param  list<array{label: string, value: float|null}>  $amounts
     * @param  list<array{label: string, quantity: mixed, unit_price: float|null, total: float|null}>  $items
     * @return array<string, mixed>
     */
    private function payload(
        string $type,
        array $fields,
        array $amounts,
        array $items,
        ?string $notes,
    ): array {
        return [
            'type' => $type,
            'type_label' => UserActivityQueryService::activityTypes()[$type] ?? $type,
            'fields' => $fields,
            'amounts' => $amounts,
            'items' => $items,
            'notes' => $notes !== null && trim($notes) !== '' ? $notes : null,
        ];
    }

    private function money(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('d/m/Y H:i');
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
