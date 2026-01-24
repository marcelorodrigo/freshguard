<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Location;
use Illuminate\Database\Seeder;

class ItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all locations to associate items with
        $locations = Location::all();

        // Sample tags to use
        $sampleTags = [
            ['Promotion', 'Important'],
            ['Healthy', 'Organic'],
            ['Dessert'],
            ['Frozen', 'Important'],
            ['Promotion'],
        ];

        if ($locations->count() > 0) {
            // Create items for each location with different configurations
            foreach ($locations as $location) {
                // Create 4 items with description and tags
                Item::factory()
                    ->count(4)
                    ->for($location)
                    ->withTags($sampleTags[array_rand($sampleTags)])
                    ->create();

                // Create 3 items with description but without tags
                Item::factory()
                    ->count(3)
                    ->for($location)
                    ->create();

                // Create 3 items without description but with tags
                Item::factory()
                    ->count(3)
                    ->for($location)
                    ->withoutDescription()
                    ->withTags()
                    ->create();

                // Create 2 items with 30-day expiration notification
                Item::factory()
                    ->count(2)
                    ->for($location)
                    ->withTags(['Important', 'Healthy'])
                    ->create();

                // Create 2 items with 60-day expiration notification
                Item::factory()
                    ->count(2)
                    ->for($location)
                    ->withTags()
                    ->create();
            }
        } else {
            // If no locations exist, create items with their own locations

            // 10 items with tags and description
            Item::factory()
                ->count(10)
                ->withTags($sampleTags[array_rand($sampleTags)])
                ->create();

            // 5 items with description but without tags
            Item::factory()
                ->count(5)
                ->create();

            // 5 items without description but with tags
            Item::factory()
                ->count(5)
                ->withoutDescription()
                ->withTags()
                ->create();

            // 3 items with 30-day expiration notification
            Item::factory()
                ->count(3)
                ->withTags(['Important', 'Healthy'])
                ->create();

            // 3 items with 60-day expiration notification
            Item::factory()
                ->count(3)
                ->withTags()
                ->create();
        }
    }
}
