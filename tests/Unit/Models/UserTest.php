<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_create_a_user_with_factory(): void
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(User::class, $user);
        $this->assertNotNull($user->id);
        $this->assertNotNull($user->email);
    }

    public function test_fillable_attributes(): void
    {
        $data = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'secret-password',
        ];
        $user = User::create($data);
        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('test@example.com', $user->email);
        $this->assertNotNull($user->password);
    }

    public function test_hidden_attributes_are_not_serialized(): void
    {
        $user = User::factory()->create([
            'password' => 'secret-password',
            'remember_token' => Str::random(10),
        ]);
        $array = $user->toArray();
        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('remember_token', $array);
    }

    public function test_casts_email_verified_at_as_datetime(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $user->email_verified_at);
    }

    public function test_casts_password_as_hashed(): void
    {
        $user = User::factory()->create([
            'password' => 'plain-password',
        ]);
        $this->assertNotEquals('plain-password', $user->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('plain-password', $user->password));
    }
}
