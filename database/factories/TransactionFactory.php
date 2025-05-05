<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    protected $model = \App\Models\Transaction::class;

    /**
     * Defines the default set of attributes for a new Transaction model instance.
     *
     * Generates randomized values for stock association, transaction type, timestamp within the last 30 days, and quantity for use in database seeding or testing.
     *
     * @return array Associative array of Transaction attributes.
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
