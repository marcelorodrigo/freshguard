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
                // Create 1-3 batches per (item, location) with unique expirations
                $expiresAtDates = [];
                $batchCount = fake()->numberBetween(1, 3);
                for ($i = 0; $i < $batchCount; $i++) {
                    do {
                        $expiration = now()->addDays(fake()->numberBetween(1, 180))->toDateString();
                    } while (in_array($expiration, $expiresAtDates, true));
                    $expiresAtDates[] = $expiration;

                    Batch::factory()->create([
                        'item_id' => $item->id,
                        'location_id' => $location->id,
                        'expires_at' => $expiration,
                        'quantity' => fake()->numberBetween(1, 50),
                    ]);
                }
            }
        }

        // Deliberately attempt to create a duplicate batch (test edge case):
        if ($items->count() > 0 && $locations->count() > 0) {
            $item = $items->first();
            $location = $locations->first();
            $expiration = now()->addDays(90)->toDateString();
            // Create first batch
            Batch::factory()->create([
                'item_id' => $item->id,
                'location_id' => $location->id,
                'expires_at' => $expiration,
                'quantity' => 10,
            ]);
            // Attempt to create duplicate - wrap in try/catch for tests or keep commented for validation demonstration
            try {
                Batch::factory()->create([
                    'item_id' => $item->id,
                    'location_id' => $location->id,
                    'expires_at' => $expiration,
                    'quantity' => 10,
                ]);
            } catch (\Exception $e) {
                // This should trigger uniqueness violation if constraints are in place
                // Log/ignore for test
            }
        }

    }
}
