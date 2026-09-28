<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            // Rattache la boutique à une marque de la même organisation :
            // évalué après fusion des attributs passés à create(), donc voit
            // bien un organization_id explicitement fourni par l'appelant.
            'brand_id' => fn (array $attributes) => Brand::factory()->create(['organization_id' => $attributes['organization_id']])->id,
            'name' => fake()->randomElement(['Adidas', 'Carré', 'Mango', 'Grand Store']),
            'whatsapp_phone_number_id' => (string) fake()->numerify('##############'),
            'whatsapp_access_token' => fake()->uuid(),
        ];
    }
}
