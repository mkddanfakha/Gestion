<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quote extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_number',
        'customer_id',
        'user_id',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'status',
        'valid_until',
        'notes',
        'quote_date',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'quote_date' => 'datetime',
        'valid_until' => 'date',
    ];

    /**
     * Relation avec le client
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Relation avec l'utilisateur
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation avec les articles du devis
     */
    public function quoteItems(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    /**
     * Générer automatiquement un numéro de devis unique au format DEYYMMXXX
     */
    public static function generateQuoteNumber(): string
    {
        $yearMonth = date('ym'); // Format: YYMM (ex: 2511 pour November 2025)
        $prefix = 'DE' . $yearMonth; // Format: DEYYMM (ex: DE2511)

        // Trouver le dernier devis du mois avec le nouveau format
        $lastQuoteThisMonth = self::where('quote_number', 'like', $prefix . '%')
            ->orderBy('quote_number', 'desc')
            ->first();

        if ($lastQuoteThisMonth) {
            // Extraire le numéro séquentiel (derniers 3 caractères)
            $lastNumber = (int) substr($lastQuoteThisMonth->quote_number, -3);
            $nextNumber = $lastNumber + 1;
        } else {
            // Si aucun devis ce mois, commencer à 001
            $nextNumber = 1;
        }

        // Limiter à 999 devis par mois (3 chiffres)
        if ($nextNumber > 999) {
            throw new \Exception('Limite de 999 devis par mois atteinte. Veuillez contacter l\'administrateur.');
        }

        $quoteNumber = $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

        // Vérifier si le numéro existe déjà et ajuster si nécessaire
        $attempt = 0;
        while (self::where('quote_number', $quoteNumber)->exists() && $attempt < 1000) {
            $attempt++;
            $nextNumber++;
            if ($nextNumber > 999) {
                throw new \Exception('Limite de 999 devis par mois atteinte. Veuillez contacter l\'administrateur.');
            }
            $quoteNumber = $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        }

        // Si max tentatives atteint, utiliser un timestamp pour garantir l'unicité
        if ($attempt >= 1000) {
            $quoteNumber = $prefix . str_pad((time() % 1000), 3, '0', STR_PAD_LEFT);
        }

        return $quoteNumber;
    }

    /**
     * Vérifier si le devis est expiré
     */
    public function isExpired(): bool
    {
        if (!$this->valid_until) {
            return false;
        }
        return $this->valid_until->isPast() && $this->status !== 'accepted' && $this->status !== 'rejected';
    }

    /**
     * Vérifier si le devis peut être converti en vente
     */
    public function canBeConvertedToSale(): bool
    {
        return $this->status === 'accepted' || $this->status === 'sent';
    }
}
