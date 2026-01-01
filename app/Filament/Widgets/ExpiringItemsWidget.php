<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Item;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class ExpiringItemsWidget extends BaseWidget implements HasTable
{
    use InteractsWithTable;

    public function getHeading(): string
    {
        return __('Next Expiring Items');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getQuery())
            ->defaultPaginationPageOption(10)
            ->paginated([10])
            ->defaultSort('earliest_batch_expiration', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Item'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('location.name')
                    ->label(__('Location'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('earliest_batch_expiration')
                    ->label(__('Expiring In'))
                    ->date()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label(__('Quantity'))
                    ->numeric()
                    ->sortable(),
            ]);
    }

    /**
     * Returns query for the next 10 items with the soonest (non-null) batch expirations.
     *
     * @return Builder<Item>
     */
    private function getQuery(): Builder
    {
        return Item::query()
            ->with(['location'])
            ->whereHas('batches', function ($q) {
                $q->whereNotNull('expires_at');
            })
            ->addSelect([
                'earliest_batch_expiration' => \App\Models\Batch::query()
                    ->select('expires_at')
                    ->whereColumn('item_id', 'items.id')
                    ->whereNotNull('expires_at')
                    ->orderBy('expires_at')
                    ->limit(1),
            ]);

    }
}
