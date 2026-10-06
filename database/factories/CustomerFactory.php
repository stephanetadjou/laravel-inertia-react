<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'phone_e164' => '241'.fake()->randomElement(['6', '7']).fake()->unique()->numerify('#######'),
            'name' => fake()->name(),
            'lifecycle_stage' => Customer::STAGE_CONTACT,
        ];
    }
}
