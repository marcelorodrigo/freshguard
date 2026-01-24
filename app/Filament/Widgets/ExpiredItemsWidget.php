<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Item;
use Filament\Tables;
use Filament\Widgets\TableWidget as BaseTableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ExpiredItemsWidget extends BaseTableWidget
{
    protected static ?string $heading = 'Expired Items';

    protected static ?int $sort = 1;

    protected function getTableQuery(): Builder
    {
        $now = Carbon::now();

        // Subquery for earliest batch expiration per item
        return Item::query()
            ->whereHas('batches', function (Builder $query) use ($now) {
                $query->where('expires_at', '<', $now);
            })
            ->where(function (Builder $query) use ($now) {
                // Only if earliest batch is indeed expired (must be the minimum expires_at < now)
                $query->whereRaw('(
                    SELECT MIN(expires_at) FROM batches WHERE batches.item_id = items.id
                ) < ?', [$now]);
            })
            ->with(['batches' => function ($query) {
                $query->orderBy('expires_at');
            }, 'location'])
            ->orderByRaw('(
                SELECT MIN(expires_at) FROM batches WHERE batches.item_id = items.id
            ) DESC')
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
