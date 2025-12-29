<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;

class SetFirstUserAsAdmin
{
    public function __construct(private Log $log)
    {
    }

    /**
     * Handle the event.
     * @param Registered $event
     * @return void
     */
    public function handle(Registered $event): void
    {
        /** @var User $user */
        $user = $event->user;

        $this->log->info('User registered', ['email' => $user->email]);
        // Check if this is the first user being registered
        if (User::query()->count() === 1) {
            $user->update(['is_admin' => true]);
        }
    }
}

