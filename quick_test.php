<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Sale;

echo "Test de la relation Sale->saleItems:\n";
echo "===================================\n\n";

// Test simple
$sale = Sale::first();
if ($sale) {
    echo "Vente trouvée: {$sale->sale_number}\n";
    echo "Articles: " . $sale->saleItems->count() . "\n";
} else {
    echo "Aucune vente trouvée\n";
}

echo "\nTest terminé.\n";
