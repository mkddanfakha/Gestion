<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'sku' => 'PF'.strtoupper(fake()->unique()->bothify('????')),
            'price' => fake()->randomFloat(2, 100, 10000),
            'cost_price' => fake()->randomFloat(2, 50, 5000),
            'stock_quantity' => 12,
            'min_stock_level' => 5,
            'unit' => 'pièce',
            'category_id' => fn () => Category::create([
                'name' => 'Catégorie '.fake()->unique()->word(),
                'color' => '#3B82F6',
            ])->id,
            'is_active' => true,
        ];
    }
}
