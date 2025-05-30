<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Location>
 */
class LocationFactory extends Factory
{
    /**
     * Returns the default attribute values for a new Location instance.
     *
     * The generated Location will have a randomly selected name and description, and no parent assigned by default.
     *
     * @return array<string, mixed> Default attributes for the Location model.
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
        ];
    }

    /**
     * Sets the generated Location's parent to the given Location or to a newly created Location.
     *
     * If a parent Location is provided, its ID is assigned as the parent_id. If not, a new Location instance is generated and assigned as the parent.
     *
     * @param Location|null $parent Optional parent Location to assign.
     * @return $this Factory instance with updated parent_id state.
     */
    public function withParent(?Location $parent = null): self
    {
        return $this->state(function (array $attributes) use ($parent) {
            return [
                'parent_id' => $parent ? $parent->id : Location::factory(),
            ];
        });
    }
}
