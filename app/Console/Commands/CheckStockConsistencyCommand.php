<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class CheckStockConsistencyCommand extends Command
{
    protected $signature = 'stock:check-consistency
                            {--json : Sortie JSON pour scripts}';

    protected $description = 'Diagnostique la cohérence ProductStock MAIN, miroir legacy et journal stock_movements';

    public function handle(): int
    {
        $issues = collect();

        $primaryCompany = Company::getInstance();
        $mainStore = $primaryCompany->defaultStore;

        if (! $primaryCompany) {
            $issues->push([
                'level' => 'error',
                'code' => 'missing_company',
                'message' => 'Aucune entreprise configurée.',
            ]);
        }

        if ($primaryCompany && ! $mainStore) {
            $issues->push([
                'level' => 'error',
                'code' => 'missing_main_store',
                'company_id' => $primaryCompany->id,
                'message' => "Entreprise #{$primaryCompany->id} — magasin MAIN absent.",
            ]);
        }

        if ($primaryCompany) {
            $defaultStoresCount = Store::query()
                ->where('company_id', $primaryCompany->id)
                ->where('is_default', true)
                ->count();

            if ($defaultStoresCount === 0 && $mainStore) {
                $issues->push([
                    'level' => 'error',
                    'code' => 'no_default_store_flag',
                    'company_id' => $primaryCompany->id,
                    'message' => "Entreprise #{$primaryCompany->id} — aucun magasin marqué is_default.",
                ]);
            }

            if ($defaultStoresCount > 1) {
                $issues->push([
                    'level' => 'error',
                    'code' => 'multiple_default_stores',
                    'company_id' => $primaryCompany->id,
                    'message' => "Entreprise #{$primaryCompany->id} — {$defaultStoresCount} magasins is_default=true.",
                ]);
            }
        }

        if ($mainStore) {
            $this->checkProductsMissingMainStock($issues, $mainStore);
            $this->checkLegacyMirrorDivergence($issues, $mainStore);
            $this->checkProductStocks($issues, $primaryCompany, $mainStore);
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $issues->isEmpty(),
                'issues' => $issues->values()->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $issues->isEmpty() ? self::SUCCESS : self::FAILURE;
        }

        if ($issues->isEmpty()) {
            $this->info('Cohérence stock OK.');

            return self::SUCCESS;
        }

        $this->error(sprintf('%d anomalie(s) détectée(s).', $issues->count()));

        foreach ($issues as $issue) {
            $prefix = ($issue['level'] ?? 'error') === 'ok' ? '[OK]' : '[ERROR]';
            $this->line("{$prefix} {$issue['message']}");
        }

        return self::FAILURE;
    }

    protected function checkProductsMissingMainStock(Collection $issues, Store $mainStore): void
    {
        Product::query()
            ->select(['id', 'name', 'stock_quantity'])
            ->orderBy('id')
            ->each(function (Product $product) use ($issues, $mainStore): void {
                $hasMainStock = ProductStock::query()
                    ->where('product_id', $product->id)
                    ->where('store_id', $mainStore->id)
                    ->exists();

                if (! $hasMainStock) {
                    $issues->push([
                        'level' => 'error',
                        'code' => 'missing_main_product_stock',
                        'product_id' => $product->id,
                        'message' => "Product #{$product->id} — ProductStock MAIN manquant ({$product->name}).",
                    ]);
                }
            });
    }

    protected function checkLegacyMirrorDivergence(Collection $issues, Store $mainStore): void
    {
        ProductStock::query()
            ->where('store_id', $mainStore->id)
            ->with('product:id,name,stock_quantity')
            ->orderBy('product_id')
            ->each(function (ProductStock $productStock) use ($issues): void {
                $product = $productStock->product;

                if (! $product) {
                    return;
                }

                $legacy = (int) $product->stock_quantity;
                $current = (int) $productStock->quantity;

                if ($legacy === $current) {
                    return;
                }

                $issues->push([
                    'level' => 'error',
                    'code' => 'legacy_mirror_divergence',
                    'product_id' => $product->id,
                    'legacy_stock_quantity' => $legacy,
                    'product_stock_quantity' => $current,
                    'message' => "Product #{$product->id} — divergence : legacy={$legacy} / stock={$current}",
                ]);
            });
    }

    protected function checkProductStocks(Collection $issues, Company $primaryCompany, Store $mainStore): void
    {
        ProductStock::query()
            ->with(['product:id,name', 'store:id,name,code,company_id,is_default'])
            ->orderBy('id')
            ->each(function (ProductStock $productStock) use ($issues, $primaryCompany, $mainStore): void {
                if (! $productStock->product) {
                    $issues->push([
                        'level' => 'error',
                        'code' => 'orphan_product_stock',
                        'product_stock_id' => $productStock->id,
                        'product_id' => $productStock->product_id,
                        'message' => "ProductStock #{$productStock->id} — produit #{$productStock->product_id} introuvable.",
                    ]);
                }

                $store = $productStock->store;

                if (! $store) {
                    $issues->push([
                        'level' => 'error',
                        'code' => 'missing_store',
                        'product_stock_id' => $productStock->id,
                        'store_id' => $productStock->store_id,
                        'message' => "ProductStock #{$productStock->id} — magasin #{$productStock->store_id} introuvable.",
                    ]);

                    return;
                }

                if ($store->company_id !== $primaryCompany->id) {
                    $issues->push([
                        'level' => 'error',
                        'code' => 'cross_company_store',
                        'product_stock_id' => $productStock->id,
                        'product_id' => $productStock->product_id,
                        'store_id' => $store->id,
                        'message' => "ProductStock #{$productStock->id} — magasin entreprise #{$store->company_id} incompatible avec entreprise primaire #{$primaryCompany->id}.",
                    ]);
                }

                $latestMovement = StockMovement::query()
                    ->where('product_id', $productStock->product_id)
                    ->where('store_id', $productStock->store_id)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->first();

                $currentQuantity = (int) $productStock->quantity;

                if (! $latestMovement) {
                    if ($currentQuantity !== 0) {
                        $issues->push([
                            'level' => 'error',
                            'code' => 'missing_movements',
                            'product_stock_id' => $productStock->id,
                            'product_id' => $productStock->product_id,
                            'store_id' => $productStock->store_id,
                            'product_stock_quantity' => $currentQuantity,
                            'message' => "ProductStock #{$productStock->id} (produit #{$productStock->product_id}) — aucun mouvement alors que stock={$currentQuantity}.",
                        ]);
                    }

                    return;
                }

                $movementQuantityAfter = (int) $latestMovement->quantity_after;

                if ($movementQuantityAfter !== $currentQuantity) {
                    $issues->push([
                        'level' => 'error',
                        'code' => 'movement_quantity_mismatch',
                        'product_stock_id' => $productStock->id,
                        'product_id' => $productStock->product_id,
                        'store_id' => $productStock->store_id,
                        'product_stock_quantity' => $currentQuantity,
                        'latest_movement_quantity_after' => $movementQuantityAfter,
                        'latest_movement_id' => $latestMovement->id,
                        'message' => "ProductStock #{$productStock->id} (produit #{$productStock->product_id}) — stock={$currentQuantity}, dernier mouvement={$movementQuantityAfter}.",
                    ]);
                }
            });
    }
}
