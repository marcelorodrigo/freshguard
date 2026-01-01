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

class ExpiredItemsWidget extends BaseWidget implements HasTable
{
    use InteractsWithTable;

    public function getHeading(): string
    {
        return __('Expired Items');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getQuery())
            ->defaultPaginationPageOption(10)
            ->paginated([10])
            ->defaultSort('earliest_batch_expiration', 'desc')
            ->heading($this->getHeading())
            ->emptyStateHeading($this->getEmptyStateHeading())
            ->emptyStateDescription($this->getEmptyStateDescription())
            ->emptyStateIcon($this->getEmptyStateIcon())
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
                    ->label(__('Expired On'))
                    ->date()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label(__('Quantity'))
                    ->numeric()
                    ->sortable(),
            ]);
    }

    /**
     * Query for items whose earliest batch expiration is in the past.
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
            ])
            ->whereRaw('(
                select min(batches.expires_at)
                from batches
                where batches.item_id = items.id
                    and batches.expires_at is not null
            ) < ?', [now()]);
    }

    /**
     * Add custom styling to distinguish expired items widget visually.
     */
    /**
     * @return array<string, string>
     */
    public function getExtraAttributes(): array
    {
        return [
            'class' => 'bg-red-50 border-l-4 border-red-300',
        ];
    }

    public function getEmptyStateIcon(): ?string
    {
        return 'heroicon-o-archive-box-x-mark';
    }

    public function getEmptyStateHeading(): ?string
    {
        return __('Expired Items');
    }

    public function getEmptyStateDescription(): ?string
    {
        return __('Great job! No tracked products have expired.');
    }
}
