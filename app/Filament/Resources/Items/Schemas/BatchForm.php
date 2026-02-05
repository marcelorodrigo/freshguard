<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class BatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Batch Details'))
                    ->schema([
                        Select::make('location_id')
                            ->label(__('Location'))
                            ->relationship('location', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        DatePicker::make('expires_at')
                            ->required()
                            ->default(Carbon::now()->addDays(7))
                            ->label(__('Expiration Date'))
                            ->columnSpan(['sm' => 1, 'md' => 1]),
                        TextInput::make('quantity')
                            ->integer()
                            ->required()
                            ->minValue(1)
                            ->label(__('Quantity'))
                            ->columnSpan(['sm' => 1, 'md' => 1]),
                    ])
                    ->compact(),
            ]);
    }
}
