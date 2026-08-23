<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\BarcodeLookup;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\OpenFoodFactsBarcodeLookup;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use OpenFoodFacts\Api;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BarcodeLookup::class, function (): BarcodeLookup {
            $geography = config('openfoodfacts.geography', 'world');

            if (! is_string($geography)) {
                $geography = 'world';
            }

            $api = new Api(
                'food',
                $geography,
                null,
                OpenFoodFactsBarcodeLookup::buildClient(),
                null,
            );

            return new OpenFoodFactsBarcodeLookup($api);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.env') === 'production') {
            \URL::forceScheme('https');
        }
        Gate::policy(User::class, UserPolicy::class);
    }
}
