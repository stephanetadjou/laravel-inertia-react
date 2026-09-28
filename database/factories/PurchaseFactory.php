<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Purchase;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'store_id' => Store::factory(),
            'amount' => fake()->numberBetween(5000, 500000),
            'quantity' => fake()->numberBetween(1, 5),
            'product_category' => fake()->randomElement(['vêtements', 'chaussures', 'accessoires']),
            'purchased_at' => fake()->dateTimeBetween('-1 year')->format('Y-m-d'),
        ];
    }
}
