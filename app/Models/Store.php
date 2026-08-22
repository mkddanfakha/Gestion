<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    public const CODE_MAIN = 'MAIN';

    public const NAME_MAIN = 'Magasin principal';

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'is_default',
        'is_active',
        'address',
        'phone',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Store $store): void {
            if ($store->is_default) {
                static::query()
                    ->where('company_id', $store->company_id)
                    ->when($store->exists, fn ($query) => $query->whereKeyNot($store->id))
                    ->update(['is_default' => false]);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function productStocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function inventorySessions(): HasMany
    {
        return $this->hasMany(InventorySession::class);
    }

    /**
     * Crée ou récupère le magasin principal d'une entreprise (idempotent).
     */
    public static function ensureDefaultForCompany(Company $company): self
    {
        return static::query()->firstOrCreate(
            [
                'company_id' => $company->id,
                'code' => self::CODE_MAIN,
            ],
            [
                'name' => self::NAME_MAIN,
                'is_default' => true,
                'is_active' => true,
            ],
        );
    }

    /**
     * Initialise les lignes product_stocks pour le magasin principal
     * de l'entreprise primaire de l'installation (idempotent).
     *
     * MKD-Pro est mono-entreprise par installation : le catalogue produits
     * n'a pas encore de company_id, seul le magasin MAIN de l'entreprise
     * primaire reçoit les quantités copiées depuis products.stock_quantity.
     */
    public static function initializeProductStocksForPrimaryCompany(): int
    {
        $primaryCompany = Company::query()->orderBy('id')->first();

        if (! $primaryCompany) {
            return 0;
        }

        $mainStore = self::ensureDefaultForCompany($primaryCompany);
        $created = 0;

        Product::query()
            ->select(['id', 'stock_quantity'])
            ->orderBy('id')
            ->each(function (Product $product) use ($mainStore, &$created): void {
                $stock = ProductStock::query()->firstOrNew([
                    'product_id' => $product->id,
                    'store_id' => $mainStore->id,
                ]);

                if ($stock->exists) {
                    return;
                }

                $stock->quantity = (int) $product->stock_quantity;
                $stock->save();
                $created++;
            });

        return $created;
    }
}
