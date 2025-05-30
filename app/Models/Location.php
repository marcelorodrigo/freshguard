<?php

namespace App\Models;

use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property string|null $parent_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Location|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Location> $children
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
}
