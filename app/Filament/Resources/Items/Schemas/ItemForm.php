<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Schemas;

use App\Models\Item;
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
                Select::make('location_id')
                    ->relationship('location', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->label(__('Location')),
                Textarea::make('description')
                    ->maxLength(1000)
                    ->nullable()
                    ->label(__('Description')),
                TextInput::make('quantity')
                    ->integer()
                    ->default(0)
                    ->readOnly()
                    ->helperText(__('The quantity is computed from all batches'))
                    ->label(__('Quantity'))
                    ->hidden(static fn ($record) => is_null($record)),
                TextInput::make('expiration_notify_days')
                    ->integer()
                    ->suffix(__('days'))
                    ->minValue(0)
                    ->default(0)
                    ->label(__('Notify before expiration')),
                TagsInput::make('tags')
                    ->label(__('Tags'))
                    ->suggestions(function (): array {
                        // Get all existing tags from all items
                        return Item::query()
                            ->whereNotNull('tags')
                            ->pluck('tags')
                            ->flatten()
                            ->unique()
                            ->sort()
                            ->values()
                            ->toArray();
                    })
                    ->placeholder(__('Add tags...')),
            ]);
    }
}
