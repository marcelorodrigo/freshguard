<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test at Example.com',
            'email' => 'test@example.com',
            'password' => 'test@example.com',
            'email_verified_at' => now(),
        ]);
    }
}
