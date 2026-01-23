<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class BatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Item dropdown - required
                \Filament\Forms\Components\Select::make('item_id')
                    ->relationship('item', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->label(__('Item')),
                // Location dropdown - required
                \Filament\Forms\Components\Select::make('location_id')
                    ->relationship('location', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->label(__('Location')),
                DatePicker::make('expires_at')
                    ->required()
                    ->default(Carbon::now()->addDays(7))
                    ->label(__('Expiration Date')),
                TextInput::make('quantity')
                    ->integer()
                    ->required()
                    ->minValue(1)
                    ->label(__('Quantity')),
            ]);
    }
}
