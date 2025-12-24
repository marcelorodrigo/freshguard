<?php

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $location_id
 * @property string $name
 * @property string|null $description
 * @property int $quantity
 * @property int $expiration_notify_days
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read Location $location
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Tag> $tags
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Batch> $batches
 *
 * @method static Builder<static> withBatchesExpiringWithinDays(int $days)
 **/
class Item extends Model
{
    /**
     * @use HasFactory<ItemFactory>
     */
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'location_id',
        'name',
        'description',
        'quantity',
        'expiration_notify_days',
    ];

    /**
     * Get the location that owns the item.
     *
     * @return BelongsTo<Location, Item>
     */
    public function location(): BelongsTo
    {
        /** @var BelongsTo<Location, Item> */
        return $this->belongsTo(Location::class);
    }

    /**
     * Get the tags associated with the item.
     *
     * @return BelongsToMany<Tag, Model>
     */
    public function tags(): BelongsToMany
    {
        /** @var BelongsToMany<Tag, Model> */
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Get the batches for the item.
     *
     * @return HasMany<Batch, Item>
     */
    public function batches(): HasMany
    {
        /** @var HasMany<Batch, Item> */
        return $this->hasMany(Batch::class);
    }

    /**
     * Scope a query to only include items with batches expiring within the specified number of days.
     *
     * @param  Builder<Item>  $query
     * @return Builder<Item>
     */
    public function scopeWithBatchesExpiringWithinDays(Builder $query, int $days): Builder
    {
        return $query->whereHas('batches', function (Builder $query) use ($days) {
            $query->where('expires_at', '>=', Carbon::now())
                ->where('expires_at', '<=', Carbon::now()->addDays($days));
        });
    }
}
