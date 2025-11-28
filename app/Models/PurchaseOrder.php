<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'po_number',
        'supplier_id',
        'order_date',
        'expected_delivery_date',
        'status',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'notes',
        'user_id'
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_delivery_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

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
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * Relation avec les bons de livraison
     */
    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(DeliveryNote::class, 'purchase_order_id');
    }

    /**
     * Générer automatiquement un numéro de bon de commande unique
     */
    public static function generatePONumber(): string
    {
        $datePrefix = date('ymd'); // Format: YYMMDD (ex: 251025)
        
        // Trouver le dernier numéro de BC du jour au format BCYYMMDDXXX
        $lastPO = self::where('po_number', 'like', 'BC%' . $datePrefix . '%')
            ->orderBy('po_number', 'desc')
            ->first();
        
        if ($lastPO) {
            // Extraire le numéro séquentiel (3 derniers caractères)
            $lastNumber = (int) substr($lastPO->po_number, -3);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }
        
        // Limiter à 999 BC par jour
        if ($nextNumber > 999) {
            $nextNumber = 1;
        }
        
        // Format: BCYYMMDDXXX (11 caractères)
        $poNumber = 'BC' . $datePrefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        
        // Vérifier si le numéro existe déjà
        $attempt = 0;
        while (self::where('po_number', $poNumber)->exists() && $attempt < 1000) {
            $attempt++;
            $nextNumber++;
            if ($nextNumber > 999) {
                $nextNumber = 1;
            }
            $poNumber = 'BC' . $datePrefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        }
        
        if ($attempt >= 1000) {
            $poNumber = 'BC' . $datePrefix . str_pad(time() % 1000, 3, '0', STR_PAD_LEFT);
        }
        
        return $poNumber;
    }

    /**
     * Obtenir le libellé du statut
     */
    public function getStatusLabelAttribute(): string
    {
        $labels = [
            'draft' => 'Brouillon',
            'sent' => 'Envoyé',
            'confirmed' => 'Confirmé',
            'partially_received' => 'Partiellement reçu',
            'received' => 'Reçu',
            'cancelled' => 'Annulé'
        ];

        return $labels[$this->status] ?? $this->status;
    }

    /**
     * Calculer le total du bon de commande
     */
    public function calculateTotal(): void
    {
        $this->subtotal = $this->items->sum('total_price');
        $this->total_amount = $this->subtotal + $this->tax_amount - $this->discount_amount;
    }
}
