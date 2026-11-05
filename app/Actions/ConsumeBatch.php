<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Batch;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

final class ConsumeBatch
{
    public function __invoke(string $batchId): ?string
    {
        if ($batchId === '') {
            return null;
        }

        return DB::transaction(fn (): ?string => $this->consumeLockedBatch($batchId), attempts: 3);
    }

    /**
     * Consume a single unit from the batch while holding row locks.
     *
     * Locks are acquired item-first to match Batch::updateItemQuantity() and avoid deadlocks.
     */
    private function consumeLockedBatch(string $batchId): ?string
    {
        $itemId = Batch::query()
            ->select(['id', 'item_id'])
            ->whereKey($batchId)
            ->first()
            ?->item_id;

        $item = $itemId === null
            ? null
            : Item::query()
                ->whereKey($itemId)
                ->lockForUpdate()
                ->first();

        if ($item === null) {
            return null;
        }

        $batch = Batch::query()
            ->whereKey($batchId)
            ->where('item_id', $item->getKey())
            ->lockForUpdate()
            ->first();

        if ($batch === null || $batch->quantity <= 0) {
            return null;
        }

        if ($batch->quantity === 1) {
            $batch->delete();
        } else {
            $batch->quantity--;
            $batch->save();
        }

        return $item->name;
    }
}
