<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test at Example.com',
            'email' => 'text@example.com',
            'password' => Hash::make('text@example.com'),
            'email_verified_at' => now(),
        ]);
    }
}
