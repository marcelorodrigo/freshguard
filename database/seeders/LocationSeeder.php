<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * Seeds the database with predefined hierarchical location data.
     *
     * Creates a root "Attic" location with sub-locations ("Laundry Room", "Office", "Jana's Office"), and additional main locations ("Kitchen", "Storage", "Living Room") with their respective descriptions.
     */
    public function run(): void
    {
        // Create root locations
        $attic = Location::factory()->create([
            'name' => 'Attic',
            'description' => 'Upper level of the house',
        ]);

        // Create sub locations for the Attic
        Location::factory()->withParent($attic)->create([
            'name' => 'Laundry Room',
            'description' => 'Laundry area in the Attic',
        ]);

        Location::factory()->withParent($attic)->create([
            'name' => 'Office',
            'description' => 'Main office area in the Attic',
        ]);

        Location::factory()->withParent($attic)->create([
            'name' => 'Jana\'s Office',
            'description' => 'Jana\'s personal office in the Attic',
        ]);

        // Create other main locations
        Location::factory()->create([
            'name' => 'Kitchen',
            'description' => 'Kitchen area on the ground',
        ]);

        Location::factory()->create([
            'name' => 'Storage',
            'description' => 'Storage area in the garage',
        ]);

        Location::factory()->create([
            'name' => 'Living Room',
            'description' => null,
        ]);
    }
}
