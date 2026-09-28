<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'template_name' => 'hello_world',
            'status' => 'draft',
            'sent_count' => 0,
            'failed_count' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Campaign $campaign) {
            if ($campaign->stores()->doesntExist()) {
                $campaign->stores()->attach(Store::factory()->create());
            }
        });
    }
}
