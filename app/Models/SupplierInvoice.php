<?php

namespace App\Models;

use App\Enums\PayableType;
use App\Exceptions\InvalidPaymentTransactionException;
use App\Models\Concerns\HasAttachments;
use App\Services\SupplierPaymentTransactionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Facture fournisseur (dette).
 *
 * Montant réglé canonique (futur) : somme des payment_transactions posted
 * (payable_type = supplier_invoice). Les colonnes paid_amount, remaining_amount
 * et payment_status sont des agrégats de compatibilité ; seul un service de
 * règlement dédié pourra les mettre à jour — elles ne sont pas mass-assignables.
 */
class SupplierInvoice extends Model
{
    use HasAttachments;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_CANCELLED = 'cancelled';

    public const PAYMENT_STATUS_PENDING = 'pending';

    public const PAYMENT_STATUS_PARTIAL = 'partial';

    public const PAYMENT_STATUS_PAID = 'paid';

    protected $fillable = [
        'supplier_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'total_amount',
        'status',
        'purchase_order_id',
        'delivery_note_id',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierInvoice $invoice): void {
            $invoice->initializePaymentAggregatesForCreate();
        });

        static::updating(function (SupplierInvoice $invoice): void {
            if (! $invoice->isDirty('status')
                || $invoice->status !== self::STATUS_CANCELLED
                || $invoice->getOriginal('status') === self::STATUS_CANCELLED) {
                return;
            }

            $service = app(SupplierPaymentTransactionService::class);
            $posted = $service->sumPostedAmount(PayableType::SupplierInvoice, (int) $invoice->id);

            if ($posted > SupplierPaymentTransactionService::MONEY_EPSILON) {
                throw new InvalidPaymentTransactionException(
                    'Impossible d\'annuler cette facture fournisseur : des règlements actifs existent encore. Annulez d\'abord les règlements enregistrés.',
                );
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'payable_id')
            ->where('payable_type', PayableType::SupplierInvoice);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Brouillon',
            self::STATUS_OPEN => 'Ouverte',
            self::STATUS_CANCELLED => 'Annulée',
            default => (string) $this->status,
        };
    }

    public function getPaymentStatusLabelAttribute(): string
    {
        return match ($this->payment_status) {
            self::PAYMENT_STATUS_PENDING => 'À payer',
            self::PAYMENT_STATUS_PARTIAL => 'Partiellement payée',
            self::PAYMENT_STATUS_PAID => 'Payée',
            default => (string) $this->payment_status,
        };
    }

    /**
     * Initialise les agrégats à la création (aucun règlement ledger encore).
     */
    protected function initializePaymentAggregatesForCreate(): void
    {
        $total = round((float) $this->total_amount, 2);

        $this->paid_amount = 0;
        $this->remaining_amount = $total;
        $this->payment_status = self::PAYMENT_STATUS_PENDING;
    }
}
