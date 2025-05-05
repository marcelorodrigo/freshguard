<?php

namespace Database\Factories;

use Database\Factories\FakeProvider\CategoryProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $this->faker->addProvider(new CategoryProvider($this->faker));

        return [
            'name' => $this->faker
                ->unique()
                ->categoryName(),
        ];
    }
}
