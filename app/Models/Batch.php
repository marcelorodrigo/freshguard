<?php

namespace App\Models;

use Database\Factories\BatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $item_id
 * @property string $location_id
 * @property Carbon $expires_at
 * @property int $quantity
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read Item $item
 * @property-read Location $location
 **/
class Batch extends Model
{
    /**
     * @use HasFactory<BatchFactory>
     */
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'item_id',
        'location_id',
        'expires_at',
        'quantity',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'quantity' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saved(function (Batch $batch): void {
            $batch->updateItemQuantity();
        });

        static::deleted(function (Batch $batch): void {
            $batch->updateItemQuantity();
        });
    }

    /**
     * Get the item that owns the batch.
     *
     * @return BelongsTo<Item, Batch>
     */
    public function item(): BelongsTo
    {
        /** @var BelongsTo<Item, Batch> */
        return $this->belongsTo(Item::class);
    }

    /**
     * Get the location that owns the batch.
     *
     * @return BelongsTo<Location, Batch>
     */
    public function location(): BelongsTo
    {
        /** @var BelongsTo<Location, Batch> */
        return $this->belongsTo(Location::class);
    }

    /**
     * Update the parent item's quantity based on the sum of its batches.
     */
    protected function updateItemQuantity(): void
    {
        $this->item->update([
            'quantity' => $this->item
                ->batches()
                ->sum('quantity'),
        ]);
    }
}
