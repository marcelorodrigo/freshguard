<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    protected $model = \App\Models\Transaction::class;

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
