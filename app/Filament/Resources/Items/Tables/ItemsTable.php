<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Tables;

use App\Models\Batch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->label(__('Name')),
                TextColumn::make('location.name')
                    ->searchable()
                    ->sortable()
                    ->label(__('Location')),
                TextColumn::make('description')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('Description')),
                TextColumn::make('tags')
                    ->badge()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('Tags')),
                TextColumn::make('quantity')
                    ->numeric()
                    ->sortable()
                    ->label(__('Quantity')),
                TextColumn::make('earliest_batch_expiration')
                    ->date()
                    ->sortable()
                    ->label(__('First Batch Expiration')),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('Created')),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('Updated')),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->addSelect([
                'earliest_batch_expiration' => Batch::query()
                    ->select('expires_at')
                    ->whereColumn('item_id', 'items.id')
                    ->orderBy('expires_at', 'asc')
                    ->limit(1),
            ]))
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
