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

    /****
     * Defines the relationship indicating that this stock entry belongs to a single item.
     *
     * @return BelongsTo<Item, Stock> The Eloquent relationship to the associated Item model.
     */
    public function item(): BelongsTo
    {
        /** @var BelongsTo<Item, Stock> */
        return $this->belongsTo(Item::class, 'item_id');
    }
}
