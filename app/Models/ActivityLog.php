<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';
    public const ACTION_VALIDATE = 'validate';
    public const ACTION_CANCEL = 'cancel';
    public const ACTION_PAYMENT = 'payment';
    public const ACTION_LOGIN = 'login';
    public const ACTION_LOGOUT = 'logout';
    public const ACTION_RESTORE = 'restore';
    public const ACTION_ATTACHMENT_ADDED = 'attachment_added';
    public const ACTION_ATTACHMENT_DELETED = 'attachment_deleted';

    protected $fillable = [
        'user_id',
        'action',
        'module',
        'description',
        'subject_type',
        'subject_id',
        'ip_address',
        'user_agent',
        'old_values',
        'new_values',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            return false;
        });

        static::deleting(function () {
            return false;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function getActionLabelAttribute(): string
    {
        return match ($this->action) {
            self::ACTION_CREATE => 'Création',
            self::ACTION_UPDATE => 'Modification',
            self::ACTION_DELETE => 'Suppression',
            self::ACTION_VALIDATE => 'Validation',
            self::ACTION_CANCEL => 'Annulation',
            self::ACTION_PAYMENT => 'Paiement',
            self::ACTION_LOGIN => 'Connexion',
            self::ACTION_LOGOUT => 'Déconnexion',
            self::ACTION_RESTORE => 'Restauration',
            self::ACTION_ATTACHMENT_ADDED => 'Pièce jointe ajoutée',
            self::ACTION_ATTACHMENT_DELETED => 'Pièce jointe supprimée',
            default => ucfirst($this->action),
        };
    }

    public function getBrowserAttribute(): ?string
    {
        if (!$this->user_agent) {
            return null;
        }

        $agent = $this->user_agent;

        if (str_contains($agent, 'Edg/')) {
            return 'Microsoft Edge';
        }
        if (str_contains($agent, 'Chrome/') && !str_contains($agent, 'Edg/')) {
            return 'Google Chrome';
        }
        if (str_contains($agent, 'Firefox/')) {
            return 'Mozilla Firefox';
        }
        if (str_contains($agent, 'Safari/') && !str_contains($agent, 'Chrome/')) {
            return 'Safari';
        }
        if (str_contains($agent, 'Opera') || str_contains($agent, 'OPR/')) {
            return 'Opera';
        }

        return 'Navigateur inconnu';
    }

    public function getSubjectDisplayNameAttribute(): ?string
    {
        if (!$this->subject) {
            return null;
        }

        return ActivityLog::resolveSubjectLabel($this->subject);
    }

    public static function resolveSubjectLabel(Model $subject): string
    {
        return match (true) {
            $subject instanceof Product => $subject->name,
            $subject instanceof Customer => $subject->name,
            $subject instanceof Supplier => $subject->name,
            $subject instanceof Sale => $subject->sale_number,
            $subject instanceof PurchaseOrder => $subject->po_number,
            $subject instanceof DeliveryNote => $subject->delivery_number,
            $subject instanceof Expense => $subject->expense_number ?: $subject->title,
            $subject instanceof Company => $subject->name,
            $subject instanceof User => $subject->name,
            default => class_basename($subject) . ' #' . $subject->getKey(),
        };
    }

    public static function availableModules(): array
    {
        return [
            'Produit',
            'Client',
            'Fournisseur',
            'Bon de commande',
            'Bon de livraison',
            'Facture',
            'Dépense',
            'Paiement',
            'Entreprise',
            'Utilisateur',
            'Authentification',
        ];
    }
}
