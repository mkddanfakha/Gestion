<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Test simple de la base de données:\n";
echo "=================================\n\n";

try {
    // Test direct avec DB facade
    $salesCount = \DB::table('sales')->count();
    $saleItemsCount = \DB::table('sale_items')->count();
    
    echo "1. Comptage direct:\n";
    echo "   - Ventes: {$salesCount}\n";
    echo "   - Articles de vente: {$saleItemsCount}\n\n";
    
    // Test avec une vente spécifique
    $sale = \DB::table('sales')->first();
    if ($sale) {
        echo "2. Première vente:\n";
        echo "   - ID: {$sale->id}\n";
        echo "   - Numéro: {$sale->sale_number}\n";
        echo "   - Montant: {$sale->total_amount}\n";
        
        $itemsForSale = \DB::table('sale_items')->where('sale_id', $sale->id)->get();
        echo "   - Articles: " . $itemsForSale->count() . "\n";
        
        if ($itemsForSale->count() > 0) {
            echo "   - Détails des articles:\n";
            foreach ($itemsForSale as $item) {
                echo "     * ID: {$item->id}, Produit: {$item->product_id}, Quantité: {$item->quantity}\n";
            }
        }
    }
    
    echo "\n3. Test avec Eloquent:\n";
    $sale = \App\Models\Sale::first();
    if ($sale) {
        echo "   - Vente {$sale->id} ({$sale->sale_number}):\n";
        echo "     * Montant: {$sale->total_amount}\n";
        echo "     * Articles (relation): " . $sale->saleItems->count() . "\n";
        
        if ($sale->saleItems->count() > 0) {
            foreach ($sale->saleItems as $item) {
                echo "       - Article {$item->id}: Produit {$item->product_id}, Quantité {$item->quantity}\n";
            }
        }
    }
    
    echo "\n✅ Test terminé!\n";
    
} catch (Exception $e) {
    echo "\n❌ Erreur:\n";
    echo "   - Message: {$e->getMessage()}\n";
    echo "   - Fichier: {$e->getFile()}:{$e->getLine()}\n";
}
