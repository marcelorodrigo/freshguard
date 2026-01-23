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

        // Get all locations
        $locations = \App\Models\Location::all();
        if ($locations->count() === 0) {
            $this->command->info('No locations found. Skipping batch seeding.');

            return;
        }

        foreach ($items as $item) {
            // Pick 1-3 random locations for this item
            $itemLocations = $locations->random(min(3, $locations->count()));
            foreach ($itemLocations as $location) {
                // 1-3 batches per (item, location)
                $batchCount = fake()->numberBetween(1, 3);
                for ($i = 0; $i < $batchCount; $i++) {
                    Batch::factory()->create([
                        'item_id' => $item->id,
                        'location_id' => $location->id,
                        'expires_at' => now()->addDays(fake()->numberBetween(1, 180)),
                        'quantity' => fake()->numberBetween(1, 50),
                    ]);
                }
            }
        }

    }
}
