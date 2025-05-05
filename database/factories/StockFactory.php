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
     * Define the model's default state.
     *
     * @return array<string, mixed>
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
