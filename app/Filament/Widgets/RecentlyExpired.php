<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Items\ItemResource;
use App\Models\Batch;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Widget displaying recently expired product batches.
 *
 * Shows expired batches sorted by earliest expiration date first.
 * Responsive design: progressively reveals columns on larger screens.
 */
class RecentlyExpired extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    public function getHeading(): string
    {
        return __('Recently Expired');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                TextColumn::make('item.name')
                    ->label(__('Item'))
                    ->sortable(),
                TextColumn::make('location.name')
                    ->label(__('Location'))
                    ->visibleFrom('lg'),
                TextColumn::make('quantity')
                    ->label(__('Quantity'))
                    ->numeric()
                    ->visibleFrom('sm'),
                TextColumn::make('expires_at')
                    ->label(__('Expiration Date'))
                    ->date('d-m-Y')
                    ->visibleFrom('md')
            ])
            ->recordUrl(fn (Batch $record): string => ItemResource::getUrl('edit', ['record' => $record->item]))
            ->paginated(false)
            ->emptyStateHeading(__('Congratulations, no expired items'))
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }

    /**
     * Get the query for expired batches.
     *
     * @return Builder<Batch>
     */
    protected function getTableQuery(): Builder
    {
        return Batch::query()
            ->with(['item', 'location'])
            ->whereDate('expires_at', '<', today())
            ->orderBy('expires_at', 'asc')
            ->limit(5);
    }
}
