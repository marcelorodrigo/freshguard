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
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $this->faker->addProvider(new ItemProvider($this->faker));

        return [
            'name' => $this->faker->unique()->itemName(),
        ];
    }
}
