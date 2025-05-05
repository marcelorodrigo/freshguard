<?php

namespace App\Listeners;

use App\Enums\TransactionType;
use App\Events\TransactionCreated;

class UpdateStockQuantity
{
    /**
     * Handle the event.
     */
    public function handle(TransactionCreated $event): void
    {
        $transaction = $event->getTransaction();
        $stock = $transaction->stock;

        // Determine adjustment based on transaction type
        $adjustment = $transaction->type === TransactionType::ADD
            ? $transaction->quantity
            : -$transaction->quantity;

        $stock->quantity += $adjustment;
        $stock->save();
    }
}
