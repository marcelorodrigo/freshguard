<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(private readonly Transaction $transaction) {}

    public function getTransaction(): Transaction
    {
        return $this->transaction;
    }
}
