<?php

namespace Database\Factories;

use Database\Factories\FakeProvider\ItemProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Item>
 */
class ItemFactory extends Factory
{
    /**
     * Generates the default attributes for a new Item model instance with a unique fake name.
     *
     * @return array<string, mixed> Associative array of Item attributes.
     */
    public function definition(): array
    {
        $this->faker->addProvider(new ItemProvider($this->faker));

        return [
            'name' => $this->faker
                ->unique()
                ->itemName(),
        ];
    }
}
