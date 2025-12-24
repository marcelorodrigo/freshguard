<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $houseLocations = [
            'Bedroom',
            'Bathroom',
            'Hallway',
            'Dining Room',
            'Attic',
            'Basement',
            'Garage',
            'Pantry',
            'Closet',
            'Study',
            'Entertainment Room',
            'Guest Room',
            'Home Office',
        ];

        $locationDetails = [
            'First floor',
            'Second floor',
            'Ground floor',
        ];

        return [
            'name' => $this->faker->randomElement($houseLocations),
            'description' => $this->faker->randomElement($locationDetails),
            'parent_id' => null,
            'expiration_notify_days' => 0,
        ];
    }

    /**
     * Configure the factory to have a parent location.
     *
     * @return $this
     */
    public function withParent(?Location $parent = null): self
    {
        return $this->state(function (array $attributes) use ($parent) {
            return [
                'parent_id' => $parent?->id ?? Location::factory()->create()->id,
            ];
        });
    }
}
