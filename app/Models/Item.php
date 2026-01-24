<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string|null $barcode
 * @property string|null $description
 * @property array<int, string>|null $tags
 * @property int $quantity
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Batch> $batches
 *
 * @method static Builder<static> withBatchesExpiringWithinDays(int $days)
 *
 * @mixin Builder<static>
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
        'name',
        'barcode',
        'description',
        'tags',
        'quantity',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
        ];
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

    /**
     * Get the item quantity, defaulting to 0 if unset/null.
     */
    public function getQuantityAttribute($value): int
    {
        return $value ?? 0;
    }

    /**
     * Eloquent relationship for Filament table compatibility: gets location of batch with earliest expiration.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Location, Item>|null
     */
    public function location()
    {
        $batch = $this->batches()->orderBy('expires_at')->first();
        if ($batch) {
            return $batch->location(); // returns the BelongsTo<Location, Batch>
        }

        // Return a "null" relationship (Filament will interpret as null location)
        return null;
    }

    /**
     * Accessor for app convenience: returns Location model instance or null.
     */
    public function getLocationAttribute(): ?Location
    {
        $batch = $this->batches()->orderBy('expires_at')->first();

        return $batch ? $batch->location : null;
    }
}
