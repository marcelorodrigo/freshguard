<?php

namespace Database\Seeders;

use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Item;
use App\Models\Stock;
use App\Models\Transaction;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    private const int MAX_STOCK = 30;

    /**
     * Seed the application's database.
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
                            'quantity' => abs($quantity - $addQuantity),
                        ]);
                    })
            )
            ->count(4)
            ->create();
    }
}
