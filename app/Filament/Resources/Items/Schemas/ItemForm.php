<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label(__('Item Name')),
                Textarea::make('description')
                    ->maxLength(1000)
                    ->label(__('Description')),
                Select::make('location_id')
                    ->relationship('location', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->label(__('Location')),
                TextInput::make('quantity')
                    ->integer()
                    ->default(0)
                    ->readOnly()
                    ->helperText(__('The quantity is computed from all batches'))
                    ->label(__('Quantity')),
                TextInput::make('expiration_notify_days')
                    ->integer()
                    ->suffix(__('days'))
                    ->minValue(0)
                    ->default(0)
                    ->label(__('Notify on expiration (days)')),
                TagsInput::make('tags')
                    ->label(__('Tags')),
            ]);
    }
}
