<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Item;
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
        // Override the subquery to include future dates
        return Item::query()
            ->with(['location'])
            ->whereHas('batches', function ($q) {
                $q->whereNotNull('expires_at')->where('expires_at', '>=', now());
            })
            ->addSelect([
                'earliest_batch_expiration' => \App\Models\Batch::query()
                    ->select('expires_at')
                    ->whereColumn('item_id', 'items.id')
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '>=', now())
                    ->orderBy('expires_at')
                    ->limit(1),
            ]);
    }

    protected function getExpirationColumnLabel(): string
    {
        return __('Expiring In');
    }
}
