<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = Category::all();

        $products = [
            // Électronique
            [
                'name' => 'Smartphone Samsung Galaxy',
                'description' => 'Smartphone Android avec écran 6.1 pouces',
                'sku' => 'ELEC-001',
                'barcode' => '1234567890123',
                'price' => 599.99,
                'cost_price' => 450.00,
                'stock_quantity' => 25,
                'min_stock_level' => 5,
                'unit' => 'pièce',
                'category_id' => $categories->where('name', 'Électronique')->first()->id,
                'is_active' => true,
            ],
            [
                'name' => 'Casque Bluetooth Sony',
                'description' => 'Casque sans fil avec réduction de bruit',
                'sku' => 'ELEC-002',
                'barcode' => '1234567890124',
                'price' => 199.99,
                'cost_price' => 120.00,
                'stock_quantity' => 15,
                'min_stock_level' => 3,
                'unit' => 'pièce',
                'category_id' => $categories->where('name', 'Électronique')->first()->id,
                'is_active' => true,
            ],
            [
                'name' => 'Ordinateur portable HP',
                'description' => 'Laptop 15 pouces avec processeur Intel i5',
                'sku' => 'ELEC-003',
                'barcode' => '1234567890125',
                'price' => 899.99,
                'cost_price' => 650.00,
                'stock_quantity' => 8,
                'min_stock_level' => 2,
                'unit' => 'pièce',
                'category_id' => $categories->where('name', 'Électronique')->first()->id,
                'is_active' => true,
            ],

            // Vêtements
            [
                'name' => 'T-shirt coton homme',
                'description' => 'T-shirt en coton bio, coupe classique',
                'sku' => 'VET-001',
                'barcode' => '2234567890123',
                'price' => 24.99,
                'cost_price' => 12.00,
                'stock_quantity' => 50,
                'min_stock_level' => 10,
                'unit' => 'pièce',
                'category_id' => $categories->where('name', 'Vêtements')->first()->id,
                'is_active' => true,
            ],
            [
                'name' => 'Jean slim femme',
                'description' => 'Jean slim stretch, taille haute',
                'sku' => 'VET-002',
                'barcode' => '2234567890124',
                'price' => 79.99,
                'cost_price' => 35.00,
                'stock_quantity' => 30,
                'min_stock_level' => 5,
                'unit' => 'pièce',
                'category_id' => $categories->where('name', 'Vêtements')->first()->id,
                'is_active' => true,
            ],

            // Alimentation
            [
                'name' => 'Café en grains bio',
                'description' => 'Café arabica bio, torréfaction moyenne',
                'sku' => 'ALIM-001',
                'barcode' => '3234567890123',
                'price' => 12.99,
                'cost_price' => 6.50,
                'stock_quantity' => 100,
                'min_stock_level' => 20,
                'unit' => 'kg',
                'category_id' => $categories->where('name', 'Alimentation')->first()->id,
                'is_active' => true,
            ],
            [
                'name' => 'Chocolat noir 70%',
                'description' => 'Tablette de chocolat noir équitable',
                'sku' => 'ALIM-002',
                'barcode' => '3234567890124',
                'price' => 4.99,
                'cost_price' => 2.20,
                'stock_quantity' => 75,
                'min_stock_level' => 15,
                'unit' => 'pièce',
                'category_id' => $categories->where('name', 'Alimentation')->first()->id,
                'is_active' => true,
            ],

            // Maison & Jardin
            [
                'name' => 'Aspirateur robot',
                'description' => 'Aspirateur robot avec programmation',
                'sku' => 'MAI-001',
                'barcode' => '4234567890123',
                'price' => 299.99,
                'cost_price' => 180.00,
                'stock_quantity' => 12,
                'min_stock_level' => 3,
                'unit' => 'pièce',
                'category_id' => $categories->where('name', 'Maison & Jardin')->first()->id,
                'is_active' => true,
            ],
            [
                'name' => 'Plante verte Monstera',
                'description' => 'Plante d\'intérieur, pot 15cm',
                'sku' => 'MAI-002',
                'barcode' => '4234567890124',
                'price' => 29.99,
                'cost_price' => 15.00,
                'stock_quantity' => 20,
                'min_stock_level' => 5,
                'unit' => 'pièce',
                'category_id' => $categories->where('name', 'Maison & Jardin')->first()->id,
                'is_active' => true,
            ],

            // Sports & Loisirs
            [
                'name' => 'Ballon de football',
                'description' => 'Ballon de football taille 5, cuir synthétique',
                'sku' => 'SPO-001',
                'barcode' => '5234567890123',
                'price' => 39.99,
                'cost_price' => 20.00,
                'stock_quantity' => 40,
                'min_stock_level' => 8,
                'unit' => 'pièce',
                'category_id' => $categories->where('name', 'Sports & Loisirs')->first()->id,
                'is_active' => true,
            ],
            [
                'name' => 'Raquette de tennis',
                'description' => 'Raquette de tennis graphite, poids 300g',
                'sku' => 'SPO-002',
                'barcode' => '5234567890124',
                'price' => 89.99,
                'cost_price' => 45.00,
                'stock_quantity' => 15,
                'min_stock_level' => 3,
                'unit' => 'pièce',
                'category_id' => $categories->where('name', 'Sports & Loisirs')->first()->id,
                'is_active' => true,
            ],

            // Livres & Médias
            [
                'name' => 'Livre de cuisine française',
                'description' => 'Recettes traditionnelles de la cuisine française',
                'sku' => 'LIV-001',
                'barcode' => '6234567890123',
                'price' => 24.99,
                'cost_price' => 12.00,
                'stock_quantity' => 35,
                'min_stock_level' => 7,
                'unit' => 'pièce',
                'category_id' => $categories->where('name', 'Livres & Médias')->first()->id,
                'is_active' => true,
            ],
            [
                'name' => 'Jeu de société Monopoly',
                'description' => 'Jeu de société classique Monopoly',
                'sku' => 'LIV-002',
                'barcode' => '6234567890124',
                'price' => 34.99,
                'cost_price' => 18.00,
                'stock_quantity' => 25,
                'min_stock_level' => 5,
                'unit' => 'pièce',
                'category_id' => $categories->where('name', 'Livres & Médias')->first()->id,
                'is_active' => true,
            ],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }
}
