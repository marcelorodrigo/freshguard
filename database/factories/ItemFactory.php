<?php

namespace Database\Factories;

use App\Models\Item;
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
        return [
            'name' => fake()->words(3, true),
            'barcode' => fake()->ean13(),
            'description' => fake()->sentence(),
        ];
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this;
    }

    /**
     * Indicate that the item has no description.
     */
    public function withoutDescription(): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => null,
        ]);
    }

    /**
     * Assign the specified tags to the item.
     *
     * @param  array<int, string>  $tags
     */
    public function withTags(array $tags = []): static
    {
        $defaultTags = ['Promotion', 'Healthy', 'Dessert', 'Important', 'Organic', 'Frozen'];

        return $this->state(function () use ($tags, $defaultTags) {
            if (empty($tags)) {
                // If no tags specified, use 1-3 random default tags
                $tags = fake()->randomElements($defaultTags, fake()->numberBetween(1, 3));
            }

            return [
                'tags' => $tags,
            ];
        });
    }
}
