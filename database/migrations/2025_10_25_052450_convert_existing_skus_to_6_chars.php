<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Convertir les anciens SKUs au nouveau format (6 caractères)
        $products = DB::table('products')->get();

        foreach ($products as $product) {
            $oldSku = $product->sku;
            $categoryId = $product->category_id;

            // Générer un nouveau SKU au format CCNNNN
            $newSku = $this->generateNewSku($categoryId, $product->id);

            // Mettre à jour le SKU
            DB::table('products')
                ->where('id', $product->id)
                ->update(['sku' => $newSku]);
        }
    }

    /**
     * Génère un nouveau SKU au format CCNNNN
     */
    private function generateNewSku($categoryId, $productId): string
    {
        // Mapping des catégories vers des codes de 2 lettres
        $categoryCodes = [
            1 => 'EL', // Électronique
            2 => 'VT', // Vêtements
            3 => 'AC', // Accessoires
            4 => 'LI', // Livres
            5 => 'SP', // Sports
            6 => 'MA', // Maison
            7 => 'BE', // Beauté
            8 => 'AU', // Auto
            9 => 'JO', // Jouets
            10 => 'SA', // Santé
        ];

        // Code par défaut si catégorie non trouvée
        $categoryCode = $categoryCodes[$categoryId] ?? 'PR';

        // Générer un numéro séquentiel basé sur l'ID du produit
        $number = str_pad($productId, 4, '0', STR_PAD_LEFT);

        return $categoryCode . $number;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cette migration ne peut pas être inversée facilement
        // car nous perdons l'information sur le format original
        throw new Exception('Cette migration ne peut pas être inversée.');
    }
};
