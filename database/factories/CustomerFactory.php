<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Services\CustomerIdentityService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        $withIdentity = fake()->boolean(70);

        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('+221 77 ### ## ##'),
            'nationality' => CustomerIdentityService::SENEGAL_COUNTRY_CODE,
            'identity_document_type' => $withIdentity ? Customer::IDENTITY_TYPE_NATIONAL_ID : null,
            'identity_document_number' => $withIdentity ? fake()->unique()->numerify('############') : null,
            'birthday' => fake()->optional()->date(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'postal_code' => fake()->postcode(),
            'country' => 'Sénégal',
            'notes' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
