<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Sale;
use App\Models\SaleItem;

echo "Vérification des relations dans la base de données:\n";
echo "=================================================\n\n";

try {
    // Vérifier la structure des tables
    echo "1. Structure des tables:\n";
    
    // Vérifier les ventes
    $salesCount = Sale::count();
    echo "   - Nombre total de ventes: {$salesCount}\n";
    
    // Vérifier les articles de vente
    $saleItemsCount = SaleItem::count();
    echo "   - Nombre total d'articles de vente: {$saleItemsCount}\n\n";
    
    // Vérifier les relations
    echo "2. Vérification des relations:\n";
    
    $sales = Sale::all();
    foreach ($sales as $sale) {
        $itemsCount = SaleItem::where('sale_id', $sale->id)->count();
        echo "   - Vente {$sale->id} ({$sale->sale_number}): {$itemsCount} articles\n";
    }
    
    echo "\n3. Test avec Eloquent relations:\n";
    
    $salesWithItems = Sale::with('saleItems')->get();
    foreach ($salesWithItems as $sale) {
        echo "   - Vente {$sale->id} ({$sale->sale_number}):\n";
        echo "     * saleItems chargés: " . ($sale->relationLoaded('saleItems') ? 'OUI' : 'NON') . "\n";
        echo "     * saleItems count: " . $sale->saleItems->count() . "\n";
        
        if ($sale->saleItems->count() > 0) {
            foreach ($sale->saleItems as $item) {
                echo "       - Article {$item->id}: Produit {$item->product_id}, Quantité {$item->quantity}\n";
            }
        }
        echo "\n";
    }
    
    echo "4. Test spécifique avec une vente:\n";
    $sale = Sale::find(2);
    if ($sale) {
        echo "   - Vente {$sale->id} ({$sale->sale_number}):\n";
        
        // Test direct SQL
        $directCount = \DB::table('sale_items')->where('sale_id', $sale->id)->count();
        echo "     * Count direct SQL: {$directCount}\n";
        
        // Test avec relation
        $sale->load('saleItems');
        $relationCount = $sale->saleItems->count();
        echo "     * Count avec relation: {$relationCount}\n";
        
        // Test avec withCount
        $saleWithCount = Sale::withCount('saleItems')->find($sale->id);
        echo "     * Count avec withCount: {$saleWithCount->sale_items_count}\n";
    }
    
    echo "\n5. Vérification de la structure des données:\n";
    $sale = Sale::with('saleItems')->first();
    if ($sale) {
        echo "   - Structure de la vente:\n";
        echo "     * ID: {$sale->id}\n";
        echo "     * Numéro: {$sale->sale_number}\n";
        echo "     * Montant: {$sale->total_amount}\n";
        echo "     * Articles: " . $sale->saleItems->count() . "\n";
        
        if ($sale->saleItems->count() > 0) {
            $firstItem = $sale->saleItems->first();
            echo "     * Premier article:\n";
            echo "       - ID: {$firstItem->id}\n";
            echo "       - Sale ID: {$firstItem->sale_id}\n";
            echo "       - Product ID: {$firstItem->product_id}\n";
            echo "       - Quantité: {$firstItem->quantity}\n";
        }
    }
    
    echo "\n✅ Vérification terminée!\n";
    
} catch (Exception $e) {
    echo "\n❌ Erreur:\n";
    echo "   - Message: {$e->getMessage()}\n";
    echo "   - Fichier: {$e->getFile()}:{$e->getLine()}\n";
}
