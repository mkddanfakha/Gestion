<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class ProductStock extends Model
{
    protected $fillable = [
        'product_id',
        'store_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProductStock $productStock): void {
            $productStock->assertStoreCompatibleWithProduct();
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Empêche l'association d'un produit avec le magasin d'une autre entreprise.
     *
     * Phase 1 : le catalogue produits est rattaché implicitement à
     * Company::getInstance() (entreprise primaire de l'installation).
     */
    public function assertStoreCompatibleWithProduct(): void
    {
        $storeId = $this->store_id;
        $productId = $this->product_id;

        if (! $storeId || ! $productId) {
            return;
        }

        $store = $this->relationLoaded('store')
            ? $this->store
            : Store::query()->find($storeId);

        if (! $store) {
            throw new InvalidArgumentException('Magasin introuvable pour ce stock produit.');
        }

        $primaryCompany = Company::getInstance();

        if ($store->company_id !== $primaryCompany->id) {
            throw new InvalidArgumentException(
                'Ce stock produit ne peut pas être associé au magasin d\'une autre entreprise.',
            );
        }
    }
}
