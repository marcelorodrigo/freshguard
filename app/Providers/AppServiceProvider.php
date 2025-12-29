<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\SetFirstUserAsAdmin;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Event::listen(Registered::class, SetFirstUserAsAdmin::class);
    }
}
