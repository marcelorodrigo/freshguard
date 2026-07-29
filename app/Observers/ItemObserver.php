<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Item;
use Illuminate\Support\Facades\Cache;

class ItemObserver
{
    public function created(Item $item): void
    {
        Cache::forget('item_tag_suggestions');
    }

    public function updated(Item $item): void
    {
        if ($item->wasChanged('tags')) {
            Cache::forget('item_tag_suggestions');
        }
    }

    public function deleted(Item $item): void
    {
        Cache::forget('item_tag_suggestions');
    }
}
