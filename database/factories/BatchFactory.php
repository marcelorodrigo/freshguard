<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\Item;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Batch>
 */
class BatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'item_id' => Item::factory(),
            'expires_at' => $this->faker->dateTimeBetween('tomorrow', '+1 year'),
            'quantity' => $this->faker->numberBetween(1, 100),
        ];
    }
}
