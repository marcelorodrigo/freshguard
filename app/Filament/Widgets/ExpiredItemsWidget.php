<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Illuminate\Database\Eloquent\Builder;

class ExpiredItemsWidget extends ItemExpirationBaseWidget
{
    protected function getSortDirection(): string
    {
        return 'desc';
    }

    public function getHeading(): string
    {
        return __('Expired Items');
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

    protected function buildQuery(): Builder
    {
        return $this->buildBaseQuery()
            ->whereRaw('(
                select min(batches.expires_at)
                from batches
                where batches.item_id = items.id
                    and batches.expires_at is not null
            ) < ?', [now()]);
    }

    protected function getExpirationColumnLabel(): string
    {
        return __('Expired On');
    }

    /**
     * Add custom styling to distinguish expired items widget visually.
     *
     * @return array<string, string>
     */
    public function getExtraAttributes(): array
    {
        return [
            'class' => 'bg-red-50 border-l-4 border-red-300',
        ];
    }
}
