<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('expires_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->searchable()
                    ->label(__('Expires At')),
                TextColumn::make('quantity')
                    ->numeric()
                    ->sortable()
                    ->label(__('Quantity')),
                TextColumn::make('created_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('Created At')),
            ]);
    }
}
