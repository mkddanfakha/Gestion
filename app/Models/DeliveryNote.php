<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Services\PurchaseOrderDeliveryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryNote extends Model
{
    use HasAttachments;
    protected $fillable = [
        'delivery_number',
        'purchase_order_id',
        'supplier_id',
        'delivery_date',
        'status',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'notes',
        'invoice_number',
        'invoice_file_path',
        'invoice_file_name',
        'invoice_file_mime',
        'invoice_file_size',
        'user_id'
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    /**
     * Relation avec le bon de commande
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * Relation avec le fournisseur
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Relation avec l'utilisateur
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation avec les items
     */
    public function items(): HasMany
    {
        return $this->hasMany(DeliveryNoteItem::class);
    }

    /**
     * Générer automatiquement un numéro de bon de livraison unique
     */
    public static function generateDeliveryNumber(): string
    {
        $datePrefix = date('ymd'); // Format: YYMMDD (ex: 251025)

        // Trouver le dernier numéro de BL du jour au format BLYYMMDDXXX
        $lastDN = self::where('delivery_number', 'like', 'BL' . $datePrefix . '%')
            ->orderBy('delivery_number', 'desc')
            ->first();

        if ($lastDN) {
            // Extraire le numéro séquentiel (3 derniers caractères)
            $lastNumber = (int) substr($lastDN->delivery_number, -3);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        // Limiter à 999 BL par jour
        if ($nextNumber > 999) {
            $nextNumber = 1;
        }

        // Format: BLYYMMDDXXX (11 caractères)
        $deliveryNumber = 'BL' . $datePrefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

        // Vérifier si le numéro existe déjà
        $attempt = 0;
        while (self::where('delivery_number', $deliveryNumber)->exists() && $attempt < 1000) {
            $attempt++;
            $nextNumber++;
            if ($nextNumber > 999) {
                $nextNumber = 1;
            }
            $deliveryNumber = 'BL' . $datePrefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        }

        if ($attempt >= 1000) {
            $deliveryNumber = 'BL' . $datePrefix . str_pad(time() % 1000, 3, '0', STR_PAD_LEFT);
        }

        return $deliveryNumber;
    }

    /**
     * Obtenir le libellé du statut
     */
    public function getStatusLabelAttribute(): string
    {
        $labels = [
            'pending' => 'En attente',
            'validated' => 'Validé',
            'cancelled' => 'Annulé'
        ];

        return $labels[$this->status] ?? $this->status;
    }

    /**
     * URL publique du fichier de facture fournisseur (si stockée sur le disque public)
     */
    public function getInvoiceFileUrlAttribute(): ?string
    {
        if (!$this->invoice_file_path) {
            return null;
        }
        try {
            return \Storage::disk('public')->url($this->invoice_file_path);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Calculer le total du bon de livraison
     */
    public function calculateTotal(): void
    {
        $this->subtotal = $this->items->sum('total_price');
        $this->total_amount = $this->subtotal + $this->tax_amount - $this->discount_amount;
    }

    /**
     * Valider le bon de livraison et ajuster le stock
     */
    public function validate(): bool
    {
        return app(PurchaseOrderDeliveryService::class)->validateDeliveryNote($this);
    }

    /**
     * Annuler le bon de livraison et inverser l'impact stock si nécessaire
     */
    public function cancel(): bool
    {
        return app(PurchaseOrderDeliveryService::class)->cancelDeliveryNote($this);
    }
}
