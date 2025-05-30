<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds the application's database with a test user and invokes the location seeder.
     *
     * Creates a user with predefined credentials and runs the `LocationSeeder` to populate related data.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call([
            LocationSeeder::class,
        ]);
    }
}
