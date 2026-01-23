<?php

declare(strict_types=1);

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use Filament\Resources\Pages\ViewRecord;

class ViewLocation extends ViewRecord
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Resources\Locations\Widgets\ItemsInLocationWidget::make()
                ->locationId($this->record->id),
        ];
    }
}
