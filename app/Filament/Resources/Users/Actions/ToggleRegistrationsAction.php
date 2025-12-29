<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Actions;

use App\Models\User;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Jackiedo\DotenvEditor\Facades\DotenvEditor;
use Livewire\Component;

class ToggleRegistrationsAction
{
    public static function make(): Action
    {
        return Action::make('toggleRegistrations')
            ->label(static fn (): string => config('freshguard.registrations_enabled')
                ? __('Disable Registrations')
                : __('Enable Registrations')
            )
            ->icon(static fn (): string => config('freshguard.registrations_enabled')
                ? 'heroicon-o-lock-closed'
                : 'heroicon-o-lock-open'
            )
            ->color(static fn (): string => config('freshguard.registrations_enabled')
                ? 'danger'
                : 'success'
            )
            ->visible(static function (): bool {
                $user = Auth::user();
                return $user instanceof User && $user->isAdmin();
            })
            ->authorize(static function (): bool {
                $user = Auth::user();
                return $user instanceof User && $user->isAdmin();
            })
            ->action(function (Action $action): void {
                $currentStatus = config('freshguard.registrations_enabled');
                $newStatus = ! $currentStatus;

                // Persist the new status in the configuration
                config(['freshguard.registrations_enabled' => $newStatus]);

                // Update the .env file using Laravel Dotenv Editor
                DotenvEditor::load()
                    ->setKey('FRESHGUARD_REGISTRATIONS_ENABLED', $newStatus ? 'true' : 'false')
                    ->save();

                // Clear the config cache
                Artisan::call('config:clear');
            })
            ->after(function (Action $action): void {
                // Dispatch event to refresh the Livewire component
                $livewire = $action->getLivewire();
                if ($livewire instanceof Component) {
                    $livewire->dispatch('refresh');
                }
            })
            ->successNotificationTitle(static fn (): string => config('freshguard.registrations_enabled')
                ? __('Registrations enabled')
                : __('Registrations disabled')
            );
    }
}
