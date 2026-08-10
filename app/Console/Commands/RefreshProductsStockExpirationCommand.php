<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshProductsStockExpirationCommand extends Command
{
    protected $signature = 'products:refresh-stock-expiration
                            {--stock=50 : Quantité en stock pour tous les produits}
                            {--expiration=2026-12-31 : Date d\'expiration (Y-m-d)}
                            {--dry-run : Afficher les changements sans les appliquer}';

    protected $description = 'Met à jour la quantité en stock et la date d\'expiration de tous les produits';

    public function handle(NotificationService $notificationService): int
    {
        $products = Product::query()->orderBy('id')->get();

        if ($products->isEmpty()) {
            $this->warn('Aucun produit trouvé.');

            return self::SUCCESS;
        }

        $stockQuantity = (int) $this->option('stock');
        $expirationDate = (string) $this->option('expiration');
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? 'Mode simulation — aucune modification.' : 'Mise à jour des produits…');
        $this->line("  Stock: {$stockQuantity} | Expiration: {$expirationDate}");

        DB::transaction(function () use ($products, $stockQuantity, $expirationDate, $dryRun, $notificationService) {
            Product::withoutEvents(function () use ($products, $stockQuantity, $expirationDate, $dryRun) {
                foreach ($products as $product) {
                    $updates = [
                        'stock_quantity' => $stockQuantity,
                        'expiration_date' => $expirationDate,
                        'alert_threshold_value' => 7,
                        'alert_threshold_unit' => 'days',
                    ];

                    $this->line(sprintf('  #%d %s', $product->id, $product->name));

                    if (! $dryRun) {
                        $product->update($updates);
                    }
                }
            });

            if (! $dryRun) {
                foreach (['stock_out', 'low_stock', 'product_expired', 'product_expiring'] as $type) {
                    $notificationService->syncGroupedAlert($type);
                }
            }
        });

        $this->newLine();
        $this->info(sprintf(
            '%d produit(s) %s.',
            $products->count(),
            $dryRun ? 'seraient mis à jour' : 'mis à jour'
        ));

        return self::SUCCESS;
    }
}
