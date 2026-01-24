<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Item;
use Filament\Tables;
use Filament\Widgets\TableWidget as BaseTableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ExpiringItemsWidget extends BaseTableWidget
{
    protected static ?string $heading = 'Expiring Items';

    protected static ?int $sort = 2;

    protected function getTableQuery(): Builder
    {
        $now = Carbon::now();

        return Item::query()
            ->whereHas('batches', function (Builder $query) use ($now) {
                $query->where('expires_at', '>=', $now);
            })
            ->with(['batches' => function ($query) {
                $query->orderBy('expires_at');
            }, 'location'])
            ->orderByRaw('(
                SELECT MIN(expires_at) FROM batches WHERE batches.item_id = items.id
            ) ASC')
            ->limit(10);
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('name')->label(__('Name'))->searchable(),
            Tables\Columns\TextColumn::make('location.name')->label(__('Location'))->searchable(),
            Tables\Columns\TextColumn::make('earliest_batch_expiration')
                ->label(__('Earliest Batch Expiration'))
                ->sortable()
                ->getStateUsing(fn (Item $item) => optional($item->batches->sortBy('expires_at')->first())->expires_at?->toDateString()),
            Tables\Columns\TextColumn::make('quantity')->label(__('Quantity')),
        ];
    }
}
