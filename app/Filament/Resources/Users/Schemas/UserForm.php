<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Profile Information'))
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->label(__('Name')),
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->label(__('Email')),
                    ])
                    ->compact(),

                Section::make(__('Security'))
                    ->schema([
                        Grid::make(['sm' => 1, 'md' => 2])
                            ->schema([
                                TextInput::make('password')
                                    ->password()
                                    ->dehydrated(fn (?string $state): bool => filled($state))
                                    ->required(fn (string $context): bool => $context === 'create')
                                    ->rule(Password::defaults())
                                    ->revealable()
                                    ->maxLength(255)
                                    ->label(__('Password'))
                                    ->helperText(__('Leave empty to keep current password when editing.'))
                                    ->columnSpan(['sm' => 1, 'md' => 1]),
                                DateTimePicker::make('email_verified_at')
                                    ->nullable()
                                    ->dehydrated()
                                    ->readOnly(fn (string $context): bool => $context === 'edit')
                                    ->hidden(fn (string $context): bool => $context === 'create')
                                    ->label(__('Email Verified At'))
                                    ->helperText(__('Set to mark user email as verified.'))
                                    ->columnSpan(['sm' => 1, 'md' => 1]),
                            ]),
                    ])
                    ->compact(),

                Section::make(__('Permissions'))
                    ->schema([
                        Toggle::make('is_admin')
                            ->label(__('Administrator'))
                            ->helperText(__('Administrators can manage all users and access admin features.'))
                            ->visible(fn (): bool => Auth::user()?->isAdmin() ?? false)
                            ->disabled(fn (string $context, ?User $record): bool => $context === 'edit' && $record?->id === Auth::id())
                            ->dehydrated(),
                    ])
                    ->compact()
                    ->visible(fn (): bool => Auth::user()?->isAdmin() ?? false),
            ]);
    }
}
