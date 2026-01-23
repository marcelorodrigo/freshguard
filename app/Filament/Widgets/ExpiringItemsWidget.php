<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Batch;
use Illuminate\Database\Eloquent\Builder;

class ExpiringItemsWidget extends ItemExpirationBaseWidget
{
    protected function getSortDirection(): string
    {
        return 'asc';
    }

    public function getHeading(): string
    {
        return __('Next Expiring Items');
    }

    public function getEmptyStateIcon(): ?string
    {
        return 'heroicon-o-sparkles';
    }

    public function getEmptyStateHeading(): ?string
    {
        return __('Next Expiring Items');
    }

    public function getEmptyStateDescription(): ?string
    {
        return __('All tracked items have sufficient shelf life.');
    }

    protected function buildQuery(): Builder
    {
        return Batch::query()
            ->with(['item', 'location'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '>=', now());
    }

    protected function getExpirationColumnLabel(): string
    {
        return __('Expiring In');
    }
}
