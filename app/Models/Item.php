<?php

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read \App\Models\Category $category
 */
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    protected $fillable = ['name'];

    /**
     * @return BelongsTo<\App\Models\Category, \App\Models\Item>
     */
    public function category(): BelongsTo
    {
        /** @var BelongsTo<\App\Models\Category, \App\Models\Item> */
        return $this->belongsTo(Category::class, 'category_id');
    }
}
