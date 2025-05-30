<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Item;
use Illuminate\Database\Seeder;

class BatchSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all items
        $items = Item::all();

        if ($items->count() === 0) {
            $this->command->info('No items found. Skipping batch seeding.');
            return;
        }

        // Create different batches for each item
        foreach ($items as $item) {
            // Create a batch that expires soon (within 7 days)
            Batch::factory()->create([
                'item_id' => $item->id,
                'expires_at' => now()->addDays(rand(1, 7)),
                'quantity' => rand(1, 5),
            ]);

            // Create a batch that expires in medium term (within 30 days)
            Batch::factory()->create([
                'item_id' => $item->id,
                'expires_at' => now()->addDays(rand(8, 30)),
                'quantity' => rand(5, 15),
            ]);

            // Create a batch that expires in long term
            Batch::factory()->create([
                'item_id' => $item->id,
                'expires_at' => now()->addDays(rand(31, 180)),
                'quantity' => rand(10, 50),
            ]);

            // Randomly add 1-2 more batches for some items
            if (rand(0, 1)) {
                $extraBatches = rand(1, 2);
                Batch::factory($extraBatches)->create([
                    'item_id' => $item->id,
                ]);
            }
        }
    }
}
