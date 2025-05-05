<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the `transactions` table with columns for stock reference, transaction type, quantity, transaction date, and timestamps.
     *
     * The table includes a foreign key to the `stocks` table, an enumerated `type` column restricted to 'add' or 'remove', a non-nullable unsigned big integer `quantity`, a non-nullable `transaction_at` date, and Laravel's default `created_at` and `updated_at` timestamps.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')
                ->constrained('stocks');
            $table->enum('type', ['add', 'remove'])
                ->nullable(false);
            $table->unsignedBigInteger('quantity')
                ->nullable(false);
            $table->date('transaction_at')
                ->nullable(false);
            $table->timestamps();
        });
    }

    /**
     * Drops the transactions table if it exists.
     */
    public function down(): void
    {
        Schema::dropIfExists('transacations');
    }
};
