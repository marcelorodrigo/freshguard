<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows the first registered user to access the panel without email verification', function () {
    // Create a user without email verification
    $user = User::factory()->create([
        'email_verified_at' => null,
    ]);

    // The first user should have access to the panel
    $panel = Filament::getPanel('freshguard');
    expect($user->canAccessPanel($panel))->toBeTrue();
});

it('denies subsequent users access to the panel without email verification', function () {
    // Create two users, both without email verification
    $firstUser = User::factory()->create([
        'email_verified_at' => null,
    ]);

    $secondUser = User::factory()->create([
        'email_verified_at' => null,
    ]);

    $panel = Filament::getPanel('freshguard');

    // The first user should have access
    // The second user should not have access
    expect($firstUser->canAccessPanel($panel))->toBeTrue()
        ->and($secondUser->canAccessPanel($panel))->toBeFalse();
});

it('allows any verified user to access the panel', function () {
    // Create two users
    $firstUser = User::factory()->create([
        'email_verified_at' => null,
    ]);

    $secondUser = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $panel = Filament::getPanel('freshguard');

    // First user has access (first user exemption)
    // Second user has access (email verified)
    expect($firstUser->canAccessPanel($panel))->toBeTrue()
        ->and($secondUser->canAccessPanel($panel))->toBeTrue();
});
