<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Searches items that have positive stock for the Quick Consume page.
 *
 * Matching is tiered so the common cases stay index-backed:
 *
 * 1. Exact barcode match.
 * 2. Barcode prefix match.
 * 3. Name prefix match.
 * 4. Description prefix match.
 * 5. Name/description substring fallback, only when the tiers above
 *    returned fewer results than the requested limit.
 *
 * Results are ranked by tier and then ordered by name and id, so a search
 * that matches more than the limit always returns the same page of results.
 * Terms shorter than two characters return nothing. `%`, `_` and `\` are
 * always treated as literal text. Only items with at least one batch whose
 * quantity is greater than zero are returned.
 */
final class SearchItems
{
    private const int MIN_TERM_LENGTH = 2;

    private const int DEFAULT_LIMIT = 10;

    /**
     * @return Collection<int, Item>
     */
    public function __invoke(string $term, int $limit = self::DEFAULT_LIMIT): Collection
    {
        $term = trim($term);

        if (Str::length($term) < self::MIN_TERM_LENGTH || $limit < 1) {
            return new Collection;
        }

        $ids = $this->matchingIds($term, $limit);

        if ($ids === []) {
            return new Collection;
        }

        return $this->hydrate($ids);
    }

    /**
     * @return list<string>
     */
    private function matchingIds(string $term, int $limit): array
    {
        $escaped = addcslashes($term, '\\%_');
        $escapeChar = '\\';

        /** @var array<int, callable(): Builder<Item>> $tiers */
        $tiers = [
            fn (): Builder => $this->baseQuery()->where('barcode', $term),
            fn (): Builder => $this->baseQuery()->whereRaw('`barcode` LIKE ? ESCAPE ?', [$escaped.'%', $escapeChar]),
            fn (): Builder => $this->baseQuery()->whereRaw('`name` LIKE ? ESCAPE ?', [$escaped.'%', $escapeChar]),
            fn (): Builder => $this->baseQuery()->whereRaw('`description` LIKE ? ESCAPE ?', [$escaped.'%', $escapeChar]),
            fn (): Builder => $this->baseQuery()->where(function (Builder $query) use ($escaped, $escapeChar): void {
                $query->whereRaw('`name` LIKE ? ESCAPE ?', ['%'.$escaped.'%', $escapeChar])
                    ->orWhereRaw('`description` LIKE ? ESCAPE ?', ['%'.$escaped.'%', $escapeChar]);
            }),
        ];

        /** @var list<string> $ids */
        $ids = [];

        foreach ($tiers as $tier) {
            if (count($ids) >= $limit) {
                break;
            }

            /** @var list<string> $tierIds */
            $tierIds = $tier()
                ->when($ids !== [], fn (Builder $query): Builder => $query->whereNotIn('id', $ids))
                ->orderBy('name')
                ->orderBy('id')
                ->limit($limit - count($ids))
                ->pluck('id')
                ->all();

            $ids = array_merge($ids, $tierIds);
        }

        return $ids;
    }

    /**
     * @param  list<string>  $ids
     * @return Collection<int, Item>
     */
    private function hydrate(array $ids): Collection
    {
        $items = Item::query()
            ->select(['id', 'name', 'barcode', 'description'])
            ->with([
                'batches' => function (Relation $query): void {
                    $query
                        ->select(['id', 'item_id', 'location_id', 'expires_at', 'quantity'])
                        ->with('location:id,name')
                        ->where('quantity', '>', 0)
                        ->orderBy('expires_at')
                        ->orderBy('id');
                },
            ])
            ->whereKey($ids)
            ->get();

        /** @var array<string, int> $positions */
        $positions = array_flip($ids);

        return $items
            ->sortBy(fn (Item $item): int => $positions[$item->id])
            ->values();
    }

    /**
     * @return Builder<Item>
     */
    private function baseQuery(): Builder
    {
        return Item::query()
            ->select(['id'])
            ->whereHas('batches', fn (Builder $query): Builder => $query->where('quantity', '>', 0));
    }
}
