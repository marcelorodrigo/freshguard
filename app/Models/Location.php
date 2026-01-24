<?php

namespace App\Models;

use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property string|null $parent_id
 * @property int $expiration_notify_days
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Location|null $parent
 * @property-read Collection<int, Location> $children
 * @property-read Collection<int, Batch> $batches
 *
 * @method static LocationFactory factory($count = null, $state = [])
 */
class Location extends Model
{
    /**
     * @use HasFactory<LocationFactory>
     */
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'expiration_notify_days',
        'parent_id',
    ];

    /**
     * Get the parent location.
     *
     * @return BelongsTo<Location, Location>
     */
    public function parent(): BelongsTo
    {
        /** @var BelongsTo<Location,Location> */
        return $this->belongsTo(Location::class, 'parent_id');
    }

    /**
     * Get the child locations.
     *
     * @return HasMany<Location, Location>
     */
    public function children(): HasMany
    {
        /** @var HasMany<Location,Location> */
        return $this->hasMany(Location::class, 'parent_id');
    }

    /**
     * Get the batches in this location.
     *
     * @return HasMany<Batch, Location>
     */
    public function batches(): HasMany
    {
        /** @var HasMany<Batch, Location> */
        return $this->hasMany(Batch::class, 'location_id');
    }
}
