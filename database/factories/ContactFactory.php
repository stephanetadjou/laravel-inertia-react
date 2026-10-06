<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => fake()->name(),
            // Mobile gabonais valide (cf. PhoneNormalizer) : 241 + 6/7 + 7 chiffres.
            'phone_e164' => '241'.fake()->randomElement(['6', '7']).fake()->numerify('#######'),
            'consent_status' => fake()->randomElement(['unknown', 'opt_in', 'opt_out']),
        ];
    }
}
