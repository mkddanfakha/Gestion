<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    protected $fillable = [
        'expense_number',
        'title',
        'description',
        'amount',
        'category',
        'payment_method',
        'expense_date',
        'receipt_number',
        'vendor',
        'notes',
        'user_id'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
    ];

    /**
     * Relation avec l'utilisateur
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Générer automatiquement un numéro de dépense unique
     */
    public static function generateExpenseNumber(): string
    {
        $datePrefix = date('ymd'); // Format: YYMMDD (ex: 251025)
        
        // Trouver le dernier numéro de dépense du jour
        $lastExpenseToday = self::where('expense_number', 'like', $datePrefix . '%')
            ->orderBy('expense_number', 'desc')
            ->first();
        
        if ($lastExpenseToday) {
            // Extraire le numéro du dernier expense_number du jour (3 derniers caractères)
            $lastNumber = (int) substr($lastExpenseToday->expense_number, -3);
            $nextNumber = $lastNumber + 1;
        } else {
            // Si aucune dépense aujourd'hui, commencer à 001
            $nextNumber = 1;
        }
        
        // Limiter à 999 dépenses par jour (3 chiffres)
        if ($nextNumber > 999) {
            $nextNumber = 1; // Recommencer à 1 si on dépasse 999
        }
        
        $expenseNumber = 'EXP-' . $datePrefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        
        // Vérifier si le numéro existe déjà et ajuster si nécessaire
        $attempt = 0;
        while (self::where('expense_number', $expenseNumber)->exists() && $attempt < 1000) {
            $attempt++;
            $nextNumber++;
            if ($nextNumber > 999) {
                $nextNumber = 1;
            }
            $expenseNumber = 'EXP-' . $datePrefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        }
        
        // Si on atteint le maximum de tentatives, utiliser un timestamp pour garantir l'unicité
        if ($attempt >= 1000) {
            $expenseNumber = 'EXP-' . $datePrefix . str_pad((time() % 1000), 3, '0', STR_PAD_LEFT);
        }
        
        return $expenseNumber;
    }

    /**
     * Obtenir le libellé de la catégorie
     */
    public function getCategoryLabelAttribute(): string
    {
        $labels = [
            'fournitures' => 'Fournitures',
            'equipement' => 'Équipement',
            'marketing' => 'Marketing',
            'transport' => 'Transport',
            'formation' => 'Formation',
            'maintenance' => 'Maintenance',
            'utilities' => 'Services publics',
            'autres' => 'Autres'
        ];

        return $labels[$this->category] ?? $this->category;
    }

    /**
     * Obtenir le libellé de la méthode de paiement
     */
    public function getPaymentMethodLabelAttribute(): string
    {
        $labels = [
            'cash' => 'Espèces',
            'bank_transfer' => 'Virement bancaire',
            'credit_card' => 'Carte de crédit',
            'mobile_money' => 'Mobile Money',
            'orange_money' => 'Orange Money',
            'wave' => 'Wave',
            'check' => 'Chèque'
        ];

        return $labels[$this->payment_method] ?? $this->payment_method;
    }
}
