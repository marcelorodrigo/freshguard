<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Database\Factories\LocationFactory;
use Illuminate\Support\Str;

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
    use HasFactory;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The data type of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'string';

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
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(function (Location $location) {
            $location->id = (string) Str::uuid();
        });
    }

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
