<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Location;
use App\Models\Tag;
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
            'location_id' => Location::factory(),
            'name' => fake()->words(3, true),
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
     */
    public function withTags(array $tagIds = []): static
    {
        return $this->afterCreating(function (Item $item) use ($tagIds) {
            if (!empty($tagIds)) {
                $item->tags()->attach($tagIds);
            } else {
                // If no tags specified, attach the first 2 tags by default
                $item->tags()->attach(
                    Tag::query()->limit(2)->pluck('id')
                );
            }
        });
    }
}
