<?php

namespace App\Models;

use App\Enums\InventorySessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class InventoryItem extends Model
{
    protected $fillable = [
        'inventory_session_id',
        'product_id',
        'stock_snapshot',
        'quantity_counted',
        'counted_at',
        'counted_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'stock_snapshot' => 'integer',
            'quantity_counted' => 'integer',
            'counted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (InventoryItem $item): void {
            if ($item->isDirty('stock_snapshot') && $item->getOriginal('stock_snapshot') !== null) {
                throw new InvalidArgumentException(
                    'Le stock snapshot est immuable après le démarrage de la session.',
                );
            }

            if ($item->isDirty('quantity_counted')) {
                $session = $item->relationLoaded('inventorySession')
                    ? $item->inventorySession
                    : $item->inventorySession()->first();

                if ($session && $session->status !== InventorySessionStatus::Counting) {
                    throw new InvalidArgumentException(
                        'La quantité comptée ne peut être modifiée que pendant le comptage.',
                    );
                }
            }
        });
    }

    public function inventorySession(): BelongsTo
    {
        return $this->belongsTo(InventorySession::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function isCounted(): bool
    {
        return $this->quantity_counted !== null;
    }

    /**
     * Écart comptage vs snapshot (analyse/audit uniquement — pas le delta d'application stock).
     *
     * L'application Phase 4E utilisera : quantity_counted - stock_courant_au_moment_apply.
     */
    public function differenceFromSnapshot(): ?int
    {
        if ($this->quantity_counted === null) {
            return null;
        }

        return $this->quantity_counted - $this->stock_snapshot;
    }
}
