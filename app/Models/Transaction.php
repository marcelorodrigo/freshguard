<?php

namespace App\Models;

use App\Enums\TransactionType;
use App\Events\TransactionCreated;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property TransactionType $type
 * @property int $quantity
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Stock $stock
 */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    use Notifiable;

    protected $fillable = ['quantity', 'type'];

    protected $casts = [
        'type' => TransactionType::class,
    ];

    /**
     * @var array<mixed>
     */
    protected $dispatchesEvents = [
        'created' => TransactionCreated::class,
    ];
}
