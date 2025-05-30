<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
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
            'name' => 'Private Office',
            'description' => 'Priavate office in the Attic',
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
