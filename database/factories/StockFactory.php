<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stock>
 */
class StockFactory extends Factory
{
    protected $model = Stock::class;

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
