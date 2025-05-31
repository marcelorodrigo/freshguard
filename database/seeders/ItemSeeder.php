<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Location;
use App\Models\Tag;
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

        // Retrieve all tags for assignment
        $tags = Tag::all();
        $tagIds = $tags->pluck('id')->toArray();

        if ($locations->count() > 0) {
            // Create items for each location with different configurations
            foreach ($locations as $location) {
                // Create 4 items with description and tags
                Item::factory()
                    ->count(4)
                    ->for($location)
                    ->withTags($tagIds)
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
                    ->withExpirationNotifyDays(30)
                    ->withTags($tagIds)
                    ->create();

                // Create 2 items with 60-day expiration notification
                Item::factory()
                    ->count(2)
                    ->for($location)
                    ->withExpirationNotifyDays(60)
                    ->withTags()
                    ->create();
            }
        } else {
            // If no locations exist, create items with their own locations

            // 10 items with tags and description
            Item::factory()
                ->count(10)
                ->withTags($tagIds)
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
                ->withExpirationNotifyDays(30)
                ->withTags($tagIds)
                ->create();

            // 3 items with 60-day expiration notification
            Item::factory()
                ->count(3)
                ->withExpirationNotifyDays(60)
                ->withTags()
                ->create();
        }
    }
}
