<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Item;
use App\Models\Location;
use Illuminate\Database\Seeder;

class BatchSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get related tables
        $items = Item::all();
        $locations = Location::all();

        if ($items->isEmpty() || $locations->isEmpty()) {
            $this->command->info('No items or locations found. Skipping batch seeding.');

            return;
        }

        // Create different batches for each item
        foreach ($items as $item) {
            // Create a batch that expires soon (within 7 days)
            Batch::factory()->create([
                'item_id' => $item->id,
                'location_id' => $locations->random()->id,
                'expires_at' => now()->addDays(fake()->numberBetween(1, 7)),
                'quantity' => fake()->numberBetween(1, 5),
            ]);

            // Create a batch that expires in medium term (within 30 days)
            Batch::factory()->create([
                'item_id' => $item->id,
                'location_id' => $locations->random()->id,
                'expires_at' => now()->addDays(fake()->numberBetween(8, 30)),
                'quantity' => fake()->numberBetween(5, 15),
            ]);

            // Create a batch that expires in long term
            Batch::factory()->create([
                'item_id' => $item->id,
                'location_id' => $locations->random()->id,
                'expires_at' => now()->addDays(fake()->numberBetween(31, 180)),
                'quantity' => fake()->numberBetween(10, 50),
            ]);
        }
    }
}
