<?php

namespace App\Filament\Resources\Locations\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LocationForm
{
    /**
     * @throws \Exception
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('description'),
                Select::make('parent_id')
                    ->relationship('parent', 'name', ignoreRecord: true)
                    ->nullable()
                    ->placeholder('No Parent')
                    ->dehydrateStateUsing(fn ($state) => empty($state) ? null : $state)
            ]);
    }
}
