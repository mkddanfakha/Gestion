<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Électronique',
                'description' => 'Appareils électroniques et accessoires',
                'color' => '#3B82F6',
            ],
            [
                'name' => 'Vêtements',
                'description' => 'Vêtements pour hommes, femmes et enfants',
                'color' => '#EF4444',
            ],
            [
                'name' => 'Alimentation',
                'description' => 'Produits alimentaires et boissons',
                'color' => '#10B981',
            ],
            [
                'name' => 'Maison & Jardin',
                'description' => 'Articles pour la maison et le jardinage',
                'color' => '#8B5CF6',
            ],
            [
                'name' => 'Sports & Loisirs',
                'description' => 'Équipements sportifs et articles de loisir',
                'color' => '#F59E0B',
            ],
            [
                'name' => 'Livres & Médias',
                'description' => 'Livres, films, musique et jeux',
                'color' => '#EC4899',
            ],
        ];

        foreach ($categories as $category) {
            Category::create($category);
        }
    }
}
