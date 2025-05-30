<?php

declare(strict_types=1);

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
 * @property Carbon $expires_at
 * @property int $quantity
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read Item $item
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
}
