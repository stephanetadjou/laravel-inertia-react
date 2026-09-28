<?php

namespace Database\Factories;

use App\Models\MessageTemplate;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageTemplate>
 */
class MessageTemplateFactory extends Factory
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
            'name' => fake()->unique()->slug(2, false),
            'language' => 'fr',
            'category' => 'utility',
            'is_active' => true,
        ];
    }
}
