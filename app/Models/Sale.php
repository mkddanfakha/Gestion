<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_number',
        'customer_id',
        'user_id',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'down_payment_amount',
        'remaining_amount',
        'payment_status',
        'total_amount',
        'status',
        'payment_method',
        'notes',
        'sale_date',
        'due_date',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'down_payment_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'sale_date' => 'datetime',
        'due_date' => 'date',
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
     * Relation avec les éléments de vente
     */
    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * Boot method to handle model events
     */
    protected static function boot()
    {
        parent::boot();

        // Supprimer automatiquement les articles de vente quand une vente est supprimée
        static::deleting(function ($sale) {
            // Cette méthode sera appelée avant la suppression de la vente
            // Les contraintes de clé étrangère avec CASCADE DELETE s'occuperont du reste
            $sale->saleItems()->delete();
        });
    }

    /**
     * Générer un numéro de vente unique (format: FAYYMMXXX - 9 caractères)
     * Exemple: FA2511001 (FA = préfixe, 25 = année, 11 = mois, 001 = numéro séquentiel)
     */
    public static function generateSaleNumber(): string
    {
        $yearMonth = date('ym'); // Format: YYMM (ex: 2511 pour novembre 2025)
        $prefix = 'FA' . $yearMonth; // Format: FAYYMM (ex: FA2511)
        
        // Trouver le dernier numéro de vente du mois avec le nouveau format
        $lastSaleThisMonth = self::where('sale_number', 'like', $prefix . '%')
            ->orderBy('sale_number', 'desc')
            ->first();
        
        if ($lastSaleThisMonth) {
            // Extraire le numéro séquentiel (3 derniers caractères)
            $lastNumber = (int) substr($lastSaleThisMonth->sale_number, -3);
            $nextNumber = $lastNumber + 1;
        } else {
            // Si aucune vente ce mois, commencer à 001
            $nextNumber = 1;
        }
        
        // Limiter à 999 ventes par mois (3 chiffres)
        if ($nextNumber > 999) {
            throw new \Exception('Limite de 999 factures par mois atteinte. Veuillez contacter l\'administrateur.');
        }
        
        $saleNumber = $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        
        // Vérifier si le numéro existe déjà et ajuster si nécessaire
        $attempt = 0;
        while (self::where('sale_number', $saleNumber)->exists() && $attempt < 1000) {
            $attempt++;
            $nextNumber++;
            if ($nextNumber > 999) {
                throw new \Exception('Limite de 999 factures par mois atteinte. Veuillez contacter l\'administrateur.');
            }
            $saleNumber = $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        }
        
        // Si on atteint le maximum de tentatives, utiliser un timestamp pour garantir l'unicité
        if ($attempt >= 1000) {
            $saleNumber = $prefix . str_pad((time() % 1000), 3, '0', STR_PAD_LEFT);
        }
        
        return $saleNumber;
    }
}
