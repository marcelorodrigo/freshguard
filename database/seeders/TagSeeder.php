<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tags = [
            ['name' => 'Promotion', 'description' => 'Items bought on promotion or sale'],
            ['name' => 'Healthy', 'description' => null],
            ['name' => 'Dessert', 'description' => null],
            ['name' => 'Important', 'description' => 'High priority essential items'],
        ];

        foreach ($tags as $tag) {
            Tag::factory()->create($tag);
        }
    }
}
