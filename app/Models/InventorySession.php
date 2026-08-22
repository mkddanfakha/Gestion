<?php

namespace App\Models;

use App\Enums\InventoryScopeType;
use App\Enums\InventorySessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventorySession extends Model
{
    protected $fillable = [
        'company_id',
        'store_id',
        'reference',
        'status',
        'name',
        'description',
        'scope_type',
        'scope_value',
        'started_at',
        'submitted_at',
        'validated_at',
        'applied_at',
        'closed_at',
        'cancelled_at',
        'created_by',
        'started_by',
        'submitted_by',
        'validated_by',
        'applied_by',
        'closed_by',
        'cancelled_by',
        'application_summary',
    ];

    protected function casts(): array
    {
        return [
            'status' => InventorySessionStatus::class,
            'scope_type' => InventoryScopeType::class,
            'scope_value' => 'array',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'validated_at' => 'datetime',
            'applied_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'application_summary' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeActive($query)
    {
        return $query->whereIn(
            'status',
            array_map(
                fn (InventorySessionStatus $status) => $status->value,
                InventorySessionStatus::activeStatuses(),
            ),
        );
    }

    /**
     * Génère une référence unique par entreprise (format INVyymmXXX).
     */
    public static function generateReference(int $companyId): string
    {
        $prefix = 'INV'.date('ym');

        $lastSession = self::query()
            ->where('company_id', $companyId)
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->first();

        $nextNumber = $lastSession
            ? ((int) substr($lastSession->reference, -3)) + 1
            : 1;

        if ($nextNumber > 999) {
            throw new \RuntimeException(
                'Limite de 999 inventaires par mois atteinte pour cette entreprise.',
            );
        }

        $reference = $prefix.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);

        while (
            self::query()
                ->where('company_id', $companyId)
                ->where('reference', $reference)
                ->exists()
        ) {
            $nextNumber++;
            $reference = $prefix.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
        }

        return $reference;
    }
}
