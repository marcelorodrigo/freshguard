<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the `stocks` table with columns for item reference, expiration date, quantity, and timestamps.
     *
     * Defines a unique constraint on the combination of `item_id` and `expires_at` to prevent duplicate stock entries for the same item and expiration date.
     */
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')
                ->constrained('items');
            $table->date('expires_at')
                ->nullable(false);
            $table->unsignedBigInteger('quantity');
            $table->timestamps();

            $table->unique(['item_id', 'expires_at'], 'unique_item_expires_at');
        });
    }

    /**
     * Drops the 'stocks' table from the database if it exists, reversing the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
