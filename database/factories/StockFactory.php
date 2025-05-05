<?php

namespace Database\Factories;

use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Stock>
 */
class StockFactory extends Factory
{
    protected $model = \App\Models\Stock::class;

    /**
     * Returns the default attribute values for a new Stock model instance.
     *
     * Generates a related Item, assigns a random quantity between 10 and 100, and sets an expiration date between 1 and 30 days from now.
     *
     * @return array<string, mixed> Default attributes for Stock.
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'quantity' => $this->faker
                ->numberBetween(10, 100),
            'expires_at' => $this->faker
                ->dateTimeBetween('+1 days', '+30 days'),
        ];
    }
}
