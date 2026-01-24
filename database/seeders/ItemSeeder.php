<?php

namespace Database\Seeders;

use App\Models\Item;
use Illuminate\Database\Seeder;

class ItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create 20 items, each with at least one common tag
        Item::factory()
            ->count(20)
            ->state(function () {
                $commonTags = ['Organic', 'Frozen', 'Healthy', 'Promotion', 'Dessert', 'Important'];
                // Each item will have 1-3 tags, always including at least one from $commonTags
                $numTags = fake()->numberBetween(1, 3);
                $tags = fake()->randomElements($commonTags); // always at least one common tag
                $otherTags = array_diff(['Promotion', 'Dessert', 'Important'], $tags);
                if ($numTags > 1) {
                    $tags = array_merge($tags, fake()->randomElements($otherTags, $numTags - 1));
                }
                return ['tags' => $tags];
            })
            ->create();
    }
}
