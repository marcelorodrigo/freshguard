<?php

declare(strict_types=1);

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use Filament\Resources\Pages\ViewRecord;

class ViewLocation extends ViewRecord
{
    protected static string $resource = LocationResource::class;

    /**
     * @return array<class-string<\Filament\Widgets\Widget>|\Filament\Widgets\WidgetConfiguration>
     */
    protected function getHeaderWidgets(): array
    {
        /** @var \App\Models\Location $record */
        $record = $this->record;

        return [
            fn () => \App\Filament\Resources\Locations\Widgets\ItemsInLocationWidget::make()->locationId($record->id),
        ];
    }
}
