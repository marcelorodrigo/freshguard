<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\Stock;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_id' => Stock::factory(),
            'type' => $this->faker
                ->randomElement([TransactionType::ADD, TransactionType::REMOVE]),
            'transaction_at' => $this->faker
                ->dateTimeBetween('-30 days'),
            'quantity' => $this->faker
                ->numberBetween(1, 50),
        ];
    }
}
