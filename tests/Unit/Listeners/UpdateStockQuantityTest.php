<?php

namespace Tests\Unit\Listeners;

use App\Enums\TransactionType;
use App\Events\TransactionCreated;
use App\Listeners\UpdateStockQuantity;
use App\Models\Stock;
use App\Models\Transaction;
use PHPUnit\Framework\TestCase;

class UpdateStockQuantityTest extends TestCase
{
    public function testHandleAddsQuantityToStock(): void
    {
        // Arrange
        $stock = $this->getMockBuilder(Stock::class)
            ->onlyMethods(['save'])
            ->getMock();
        $stock->quantity = 10;

        $transaction = $this->getMockBuilder(Transaction::class)
            ->onlyMethods(['save'])
            ->getMock();
        $transaction->type = TransactionType::ADD;
        $transaction->quantity = 5;
        $transaction->stock = $stock;

        $event = new TransactionCreated($transaction);

        $stock->expects($this->once())->method('save');

        // Act
        $listener = new UpdateStockQuantity();
        $listener->handle($event);

        // Assert
        $this->assertEquals(15, $stock->quantity);
    }

    public function testHandleRemovesQuantityFromStock(): void
    {
        // Arrange
        $stock = $this->getMockBuilder(Stock::class)
            ->onlyMethods(['save'])
            ->getMock();
        $stock->quantity = 20;

        $transaction = $this->getMockBuilder(Transaction::class)
            ->onlyMethods(['save'])
            ->getMock();
        $transaction->type = TransactionType::REMOVE;
        $transaction->quantity = 3;
        $transaction->stock = $stock;

        $event = new TransactionCreated($transaction);

        $stock->expects($this->once())->method('save');

        // Act
        $listener = new UpdateStockQuantity();
        $listener->handle($event);

        // Assert
        $this->assertEquals(17, $stock->quantity);
    }
}
