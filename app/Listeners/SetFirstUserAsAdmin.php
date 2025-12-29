<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Registered;

class SetFirstUserAsAdmin
{
    /**
     * Handle the event.
     * @param Registered $event
     * @return void
     */
    public function handle(Registered $event): void
    {
        // Check if this is the first user being registered
        if (User::query()->count() === 1) {
            /** @var User $user */
            $user = $event->user;
            $user->update(['is_admin' => true]);
        }
    }
}

