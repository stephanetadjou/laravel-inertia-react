<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'customer_id' => Customer::factory(),
            'starts_at' => now()->addDay()->setTime(10, 0),
            'duration_minutes' => 30,
            'service' => 'Essayage',
            'status' => 'planned',
        ];
    }
}
