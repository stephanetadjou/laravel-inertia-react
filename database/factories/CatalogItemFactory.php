<?php

namespace Database\Factories;

use App\Models\CatalogItem;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogItem>
 */
class CatalogItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'type' => CatalogItem::TYPE_SERVICE,
            'name' => fake()->randomElement(['Coupe', 'Tresses', 'Manucure', 'Soin visage']),
            'price' => 10000,
            'duration_minutes' => 30,
            'active' => true,
        ];
    }

    public function product(): static
    {
        return $this->state(['type' => CatalogItem::TYPE_PRODUCT, 'name' => 'Shampoing', 'price' => 5000, 'duration_minutes' => null]);
    }
}
