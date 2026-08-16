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

        return DB::transaction(function () use ($batchId): ?string {
            $batchReference = Batch::query()
                ->select(['id', 'item_id'])
                ->whereKey($batchId)
                ->first();

            if ($batchReference === null) {
                return null;
            }

            $item = Item::query()
                ->whereKey($batchReference->item_id)
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
        }, attempts: 3);
    }
}
