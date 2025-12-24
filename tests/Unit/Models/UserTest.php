<?php
declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('it can create a user with factory', function () {
    $user = User::factory()->create();
    expect($user)->toBeInstanceOf(User::class)
        ->and($user->id)->not->toBeNull()
        ->and($user->email)->not->toBeNull();
});

test('fillable attributes', function () {
    $data = [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'secret-password',
    ];
    $user = User::create($data);
    expect($user->name)->toBe('Test User')
        ->and($user->email)->toBe('test@example.com')
        ->and($user->password)->not->toBeNull();
});

test('hidden attributes are not serialized', function () {
    $user = User::factory()->create([
        'password' => 'secret-password',
        'remember_token' => Str::random(10),
    ]);
    $array = $user->toArray();
    expect($array)->not->toHaveKey('password')
        ->and($array)->not->toHaveKey('remember_token');
});

test('casts email verified at as datetime', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    expect($user->email_verified_at)->toBeInstanceOf(Carbon::class);
});

test('casts password as hashed', function () {
    $user = User::factory()->create([
        'password' => 'plain-password',
    ]);
    expect($user->password)->not->toBe('plain-password')
        ->and(Hash::check('plain-password', $user->password))->toBeTrue();
});

