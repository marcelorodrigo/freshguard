<?php

declare(strict_types=1);

namespace App\Filament\Resources\Batches\Pages;

use App\Filament\Resources\Batches\BatchResource;
use Filament\Resources\Pages\ListRecords;

class ManageBatches extends ListRecords
{
    protected static string $resource = BatchResource::class;
}
