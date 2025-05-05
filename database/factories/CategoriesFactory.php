<?php

namespace Database\Factories;

use Database\Factories\FakeProvider\CategoriesProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Categories>
 */
class CategoriesFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $this->faker->addProvider(new CategoriesProvider($this->faker));

        return [
            'name' => $this->faker->categoryName(),
        ];
    }
}
