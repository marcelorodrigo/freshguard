<?php

declare(strict_types=1);

namespace App\Filament\Resources\Locations\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Location Details'))
                    ->schema([
                        Grid::make(['sm' => 1, 'md' => 2])
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->label(__('Name'))
                                    ->columnSpan(['sm' => 1, 'md' => 1]),
                                TextInput::make('description')
                                    ->maxLength(255)
                                    ->label(__('Description'))
                                    ->columnSpan(['sm' => 1, 'md' => 1]),
                            ]),
                    ])
                    ->compact(),

                Section::make(__('Settings'))
                    ->schema([
                        Grid::make(['sm' => 1, 'md' => 2])
                            ->schema([
                                TextInput::make('expiration_notify_days')
                                    ->integer()
                                    ->suffix(__('days'))
                                    ->minValue(0)
                                    ->default(0)
                                    ->label(__('Notify Expiration (Days)'))
                                    ->columnSpan(['sm' => 1, 'md' => 1]),
                                Select::make('parent_id')
                                    ->relationship('parent', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->label(__('Parent Location'))
                                    ->columnSpan(['sm' => 1, 'md' => 1]),
                            ]),
                    ])
                    ->compact(),
            ]);
    }
}
