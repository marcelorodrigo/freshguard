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
