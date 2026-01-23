<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Batch>
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
            'item_id' => Item::factory(),
            'location_id' => Location::factory(),
            'expires_at' => $this->faker->dateTimeBetween('tomorrow', '+1 year'),
            'quantity' => $this->faker->numberBetween(1, 100),
        ];
    }

    /**
     * State: Set item, location, and optional explicit expiration.
     */
    public function withItemLocationExpiration(Item $item, Location $location, ?string $expires_at = null): self
    {
        return $this->state(function () use ($item, $location, $expires_at) {
            return [
                'item_id' => $item->getKey(),
                'location_id' => $location->getKey(),
                'expires_at' => $expires_at ?? $this->faker->dateTimeBetween('tomorrow', '+1 year'),
            ];
        });
    }

    /**
     * State: Set explicit expiration only.
     */
    public function withExpiration(string $expires_at): self
    {
        return $this->state(fn () => ['expires_at' => $expires_at]);
    }
}
