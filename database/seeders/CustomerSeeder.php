<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $customers = [
            [
                'name' => 'Jean Dupont',
                'email' => 'jean.dupont@email.com',
                'phone' => '01 23 45 67 89',
                'address' => '123 Rue de la Paix',
                'city' => 'Paris',
                'postal_code' => '75001',
                'country' => 'France',
                'credit_limit' => 1000.00,
                'is_active' => true,
            ],
            [
                'name' => 'Marie Martin',
                'email' => 'marie.martin@email.com',
                'phone' => '01 98 76 54 32',
                'address' => '456 Avenue des Champs',
                'city' => 'Lyon',
                'postal_code' => '69001',
                'country' => 'France',
                'credit_limit' => 1500.00,
                'is_active' => true,
            ],
            [
                'name' => 'Pierre Durand',
                'email' => 'pierre.durand@email.com',
                'phone' => '02 11 22 33 44',
                'address' => '789 Boulevard de la République',
                'city' => 'Marseille',
                'postal_code' => '13001',
                'country' => 'France',
                'credit_limit' => 2000.00,
                'is_active' => true,
            ],
            [
                'name' => 'Sophie Bernard',
                'email' => 'sophie.bernard@email.com',
                'phone' => '03 55 66 77 88',
                'address' => '321 Rue du Commerce',
                'city' => 'Toulouse',
                'postal_code' => '31000',
                'country' => 'France',
                'credit_limit' => 800.00,
                'is_active' => true,
            ],
            [
                'name' => 'Client anonyme',
                'email' => null,
                'phone' => null,
                'address' => null,
                'city' => null,
                'postal_code' => null,
                'country' => null,
                'credit_limit' => 0.00,
                'is_active' => true,
            ],
        ];

        foreach ($customers as $customer) {
            Customer::create($customer);
        }
    }
}
