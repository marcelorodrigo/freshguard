<?php

declare(strict_types=1);

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
            'Attic',
            'Balcony',
            'Basement',
            'Bathroom',
            'Bedroom',
            'Closet',
            'Cold Storage',
            'Dining Room',
            'Entertainment Room',
            'Entryway',
            'Freezer Room',
            'Garage',
            'Garden Shed',
            'Guest Room',
            'Hallway',
            'Home Office',
            'Kitchen',
            'Laundry Room',
            'Library',
            'Living Room',
            'Mudroom',
            'Music Room',
            'Nursery',
            'Pantry',
            'Patio',
            'Playroom',
            'Porch',
            'Server Closet',
            'Storage Room',
            'Study',
            'Sunroom',
            'Utility Room',
            'Wine Cellar',
            'Workshop',
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
        return $this->state(function () use ($parent) {
            return [
                'parent_id' => $parent?->id ?? Location::factory()->create()->id,
            ];
        });
    }
}
