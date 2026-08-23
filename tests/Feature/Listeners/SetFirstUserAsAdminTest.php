<?php

declare(strict_types=1);

use App\Listeners\SetFirstUserAsAdmin;
use App\Models\User;
use Filament\Auth\Events\Registered;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(LazilyRefreshDatabase::class);

test('registration logs redact raw email and keep user id', function (): void {
    $spy = Log::spy();

    User::query()->delete();
    $user = User::create([
        'name' => 'First User',
        'email' => 'first@example.com',
        'password' => 'password',
    ]);

    (new SetFirstUserAsAdmin)->handle(new Registered($user));

    $spy->shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => ! array_key_exists('email', $context)
            && array_key_exists('user_id', $context)
            && $context['user_id'] === $user->id);
});

test('first user registration logs structured admin assignment outcome without email', function (): void {
    $spy = Log::spy();

    User::query()->delete();
    $user = User::create([
        'name' => 'First User',
        'email' => 'first@example.com',
        'password' => 'password',
    ]);

    (new SetFirstUserAsAdmin)->handle(new Registered($user));

    $spy->shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Assigned admin privileges to the first registered user'
            && ! array_key_exists('email', $context)
            && ($context['outcome'] ?? null) === 'admin_assigned');
});

test('subsequent registrations do not assign admin and redact email', function (): void {
    $spy = Log::spy();

    User::query()->delete();
    $first = User::create([
        'name' => 'First User',
        'email' => 'first@example.com',
        'password' => 'password',
    ]);
    (new SetFirstUserAsAdmin)->handle(new Registered($first));

    $second = User::create([
        'name' => 'Second User',
        'email' => 'second@example.com',
        'password' => 'password',
    ]);
    (new SetFirstUserAsAdmin)->handle(new Registered($second));

    $second->refresh();
    expect($second->is_admin)->toBeFalse();

    $spy->shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => ! array_key_exists('email', $context));
});
