<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class BatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                DateTimePicker::make('expires_at')
                    ->required()
                    ->minDate(Carbon::now())
                    ->label(__('Expiration Date')),
                TextInput::make('quantity')
                    ->integer()
                    ->required()
                    ->minValue(1)
                    ->label(__('Quantity')),
            ]);
    }
}
