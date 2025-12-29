<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Filament\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;

readonly class SetFirstUserAsAdmin
{
    /**
     * Handle the event.
     */
    public function handle(Registered $event): void
    {
        /** @var User $user */
        $user = $event->getUser();

        Log::info('User registered', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        // Check if this is the first user being registered
        if (User::query()->count() === 1) {
            $user->update(['is_admin' => true]);
            Log::info('Assigned admin privileges to the first registered user', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
        }
    }
}
