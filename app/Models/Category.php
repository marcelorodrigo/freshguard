<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, \App\Models\Item> $items
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $fillable = ['name'];

    /**
     * @return HasMany<\App\Models\Item, \App\Models\Category>
     */
    public function items(): HasMany
    {
        /** @var HasMany<\App\Models\Item, \App\Models\Category> */
        return $this->hasMany(Item::class, 'category_id');
    }
}
