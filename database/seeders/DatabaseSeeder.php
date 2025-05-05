<?php

namespace Database\Seeders;

use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Item;
use App\Models\Stock;
use App\Models\Transaction;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    private const int MAX_STOCK = 30;

    /**
     * Seeds the database with categories, items, stocks, and related transactions for testing or development.
     *
     * Creates four categories, each with four items. For each item, a stock record is generated with a random quantity and expiration date, along with two transactions representing stock addition and removal.
     */
    public function run(): void
    {
        Category::factory()
            ->has(
                Item::factory()
                    ->count(4)
                    ->afterCreating(function (Item $item) {
                        $stock = Stock::factory()->create([
                            'item_id' => $item->id,
                            'quantity' => $quantity = rand(10, self::MAX_STOCK),
                            'expires_at' => now()->addDays(rand(1, 60)),
                        ]);

                        Transaction::factory()->create([
                            'stock_id' => $stock->id,
                            'type' => TransactionType::ADD,
                            'quantity' => $addQuantity = rand($quantity + 1, self::MAX_STOCK + 1),
                        ]);

                        Transaction::factory()->create([
                            'stock_id' => $stock->id,
                            'type' => TransactionType::REMOVE,
                            'quantity' => $quantity - $addQuantity,
                        ]);
                    })
            )
            ->count(4)
            ->create();
    }
}
