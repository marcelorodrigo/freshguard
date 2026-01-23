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
                \Filament\Tables\Columns\TextColumn::make('item.name')
                    ->sortable()
                    ->searchable()
                    ->label(__('Item')),
                \Filament\Tables\Columns\TextColumn::make('location.name')
                    ->sortable()
                    ->searchable()
                    ->label(__('Location')),
                TextColumn::make('expires_at')
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->searchable()
                    ->label(__('Expiration Date')),
                TextColumn::make('quantity')
                    ->numeric()
                    ->sortable()
                    ->label(__('Quantity')),
                TextColumn::make('created_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('Created At')),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('item_id')
                    ->relationship('item', 'name')
                    ->label(__('Item')),
                \Filament\Tables\Filters\SelectFilter::make('location_id')
                    ->relationship('location', 'name')
                    ->label(__('Location')),
                \Filament\Tables\Filters\TrashedFilter::make(),
                \Filament\Tables\Filters\Filter::make('expires_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('expires_at')
                            ->label(__('Expiration Date')),
                    ])
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data) {
                        if (! empty($data['expires_at'])) {
                            $date = is_string($data['expires_at']) ? $data['expires_at'] : null;
                            if ($date !== null) {
                                $query->whereDate('expires_at', $date);
                            }
                        }
                    }),
            ])
            ->defaultSort('expires_at', 'asc');
    }
}
