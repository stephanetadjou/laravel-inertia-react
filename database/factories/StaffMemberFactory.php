<?php

namespace Database\Factories;

use App\Models\StaffMember;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffMember>
 */
class StaffMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'organization_id' => fn (array $attributes) => Store::find($attributes['store_id'])->organization_id,
            'name' => fake()->firstName(),
            'bookable_online' => true,
            'active' => true,
        ];
    }
}
