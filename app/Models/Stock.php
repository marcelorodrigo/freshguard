<?php

namespace App\Models;

use Database\Factories\StockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property-read Item $item
 * @property Carbon $expires_at
 * @property int $quantity
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Stock extends Model
{
    /** @use HasFactory<StockFactory> */
    use HasFactory;

    protected $fillable = ['quantity', 'expires_at'];

    /**
     * @return BelongsTo<Item, Stock>
     */
    public function item(): BelongsTo
    {
        /** @var BelongsTo<Item, Stock> */
        return $this->belongsTo(Item::class, 'item_id');
    }
}
