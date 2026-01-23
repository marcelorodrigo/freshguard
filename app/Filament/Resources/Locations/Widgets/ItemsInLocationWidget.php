<?php

declare(strict_types=1);

namespace App\Filament\Resources\Locations\Widgets;

use App\Models\Batch;
use Filament\Tables\Columns\TextColumn;
use Filament\Widgets\TableWidget;

class ItemsInLocationWidget extends TableWidget
{
    public ?string $locationId = null;

    /**
     * Fluent configuration of location ID for widget building
     */
    public function locationId(string $id): self
    {
        $this->locationId = $id;

        return $this;
    }

    protected static ?string $heading = 'Items at this Location';

    public function table(\Filament\Tables\Table $table): \Filament\Tables\Table
    {
        $query = Batch::query()
            ->when($this->locationId, fn ($query) => $query->where('location_id', $this->locationId))
            ->selectRaw('item_id, SUM(quantity) as total_quantity, MIN(expires_at) as next_expiration')
            ->groupBy('item_id')
            ->with('item');

        return $table->query($query)
            ->columns([
                TextColumn::make('item.name')->label(__('Item')),
                TextColumn::make('total_quantity')->label(__('Quantity')),
                TextColumn::make('next_expiration')->date()->label(__('Next Expiration')),
            ]);
    }
}
