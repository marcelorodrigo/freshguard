<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RedirectToRegisterIfNoUsers
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        if (!$this->isSafeRoute($request) && !Auth::check() && $this->noUsersExist()) {
            return Redirect::route('filament.freshguard.auth.register');
        }

        return $next($request);
    }

    private function noUsersExist(): bool
    {
        try {
            return !User::query()->exists();
        } catch (Throwable) {
            // If there is an error connecting to the database or table is not yet created, assume no users exist
            return true;
        }
    }

    private function isSafeRoute(Request $request): bool
    {
        return $request->routeIs('filament.freshguard.auth.register') ||
            $request->routeIs('up');
    }
}
