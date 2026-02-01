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
            ->contentGrid([
                'sm' => 1,
                'md' => 1,
                'lg' => 1,
            ])
            ->columns([
                TextColumn::make('location.name')
                    ->sortable()
                    ->searchable()
                    ->label(__('Location')),
                TextColumn::make('expires_at')
                    ->dateTime('d-m-Y')
                    ->sortable()
                    ->searchable()
                    ->label(__('Expiration Date')),
                TextColumn::make('quantity')
                    ->numeric()
                    ->sortable()
                    ->label(__('Quantity')),
                TextColumn::make('created_at')
                    ->dateTime('d-m-Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('Created At')),
            ])
            ->defaultSort('expires_at', 'asc');
    }
}
