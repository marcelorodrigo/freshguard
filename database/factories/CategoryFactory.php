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
     * Generates a default set of attributes for a Category model with a unique category name.
     *
     * @return array<string, mixed> Associative array containing the model's default attributes.
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
