<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignMessage>
 */
class CampaignMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'contact_id' => Contact::factory(),
            'status' => 'pending',
            'meta_message_id' => null,
            'error_message' => null,
        ];
    }
}
