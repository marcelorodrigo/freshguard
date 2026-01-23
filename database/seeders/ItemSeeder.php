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

        // Globally create items. Items are not tied to locations.
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
            ->withExpirationNotifyDays(30)
            ->withTags(['Important', 'Healthy'])
            ->create();

        // 3 items with 60-day expiration notification
        Item::factory()
            ->count(3)
            ->withExpirationNotifyDays(60)
            ->withTags()
            ->create();

    }
}
