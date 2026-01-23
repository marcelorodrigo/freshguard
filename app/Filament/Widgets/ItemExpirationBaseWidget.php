<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Batch;
use App\Models\Item;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Abstract base widget for item expiration display.
 * Subclasses define filter logic and styling via template methods.
 */
abstract class ItemExpirationBaseWidget extends BaseWidget implements HasTable
{
    use InteractsWithTable;

    public function table(Table $table): Table
    {
        return $table
            ->query($this->buildQuery())
            ->defaultPaginationPageOption(10)
            ->paginated([10])
            ->defaultSort('earliest_batch_expiration', $this->getSortDirection())
            ->heading($this->getHeading())
            ->emptyStateHeading($this->getEmptyStateHeading())
            ->emptyStateDescription($this->getEmptyStateDescription())
            ->emptyStateIcon($this->getEmptyStateIcon())
            ->columns($this->getTableColumns());
    }

    /**
     * Get the sort direction (asc/desc) for the expiration column.
     */
    abstract protected function getSortDirection(): string;

    /**
     * Get the heading for this widget.
     */
    abstract public function getHeading(): string;

    /**
     * Get the empty state icon.
     */
    abstract public function getEmptyStateIcon(): ?string;

    /**
     * Get the empty state heading.
     */
    abstract public function getEmptyStateHeading(): ?string;

    /**
     * Get the empty state description.
     */
    abstract public function getEmptyStateDescription(): ?string;

    /**
     * Build the query with widget-specific filters.
     * Subclasses implement their own filtering logic.
     *
     * @return Builder<Item>
     */
    abstract protected function buildQuery(): Builder;

    /**
     * Get the table columns configuration.
     *
     * @return array<int, TextColumn>
     */
    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('item.name')
                ->label(__('Item'))
                ->searchable()
                ->sortable(),
            TextColumn::make('location.name')
                ->label(__('Location'))
                ->searchable()
                ->sortable(),
            TextColumn::make('expires_at')
                ->label($this->getExpirationColumnLabel())
                ->date()
                ->sortable(),
            TextColumn::make('quantity')
                ->label(__('Quantity'))
                ->numeric()
                ->sortable(),
        ];
    }

    /**
     * Get the label for the expiration column.
     */
    protected function getExpirationColumnLabel(): string
    {
        return __('Expiration Date');
    }

    /**
     * Build the base query with common selections.
     *
     * @return Builder<Item>
     */
    protected function buildBaseQuery(): Builder
    {
        return Batch::query()
            ->with(['item', 'location'])
            ->whereNotNull('expires_at');
    }
}
