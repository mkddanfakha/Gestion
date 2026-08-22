<?php

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'store_id',
        'product_id',
        'type',
        'quantity',
        'quantity_before',
        'quantity_after',
        'reference_type',
        'reference_id',
        'reason',
        'user_id',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'integer',
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StockMovement $movement): void {
            $movement->created_at ??= now();
        });

        static::updating(function () {
            return false;
        });

        static::deleting(function () {
            return false;
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Vérifie si un solde initial existe déjà pour ce couple magasin/produit.
     */
    public static function hasOpeningBalance(int $companyId, int $storeId, int $productId): bool
    {
        return static::query()
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->where('type', StockMovementType::OpeningBalance->value)
            ->exists();
    }

    /**
     * Crée les mouvements opening_balance pour les ProductStock existants (idempotent).
     */
    public static function createOpeningBalancesFromExistingStocks(): int
    {
        $created = 0;

        ProductStock::query()
            ->with('store:id,company_id')
            ->orderBy('id')
            ->each(function (ProductStock $productStock) use (&$created): void {
                $store = $productStock->store;

                if (! $store) {
                    return;
                }

                if (self::hasOpeningBalance(
                    $store->company_id,
                    $productStock->store_id,
                    $productStock->product_id,
                )) {
                    return;
                }

                $quantity = (int) $productStock->quantity;

                self::create([
                    'company_id' => $store->company_id,
                    'store_id' => $productStock->store_id,
                    'product_id' => $productStock->product_id,
                    'type' => StockMovementType::OpeningBalance,
                    'quantity' => $quantity,
                    'quantity_before' => 0,
                    'quantity_after' => $quantity,
                    'reason' => 'Migration initiale du stock',
                    'metadata' => [
                        'source' => 'products.stock_quantity',
                        'migration' => 'phase_1_inventory_foundation',
                    ],
                ]);

                $created++;
            });

        return $created;
    }
}
