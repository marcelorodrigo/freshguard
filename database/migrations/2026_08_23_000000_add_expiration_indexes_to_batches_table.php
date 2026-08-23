<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds indexes for batch expiration and per-item ordering queries.
 *
 * The composite (item_id, expires_at) index supports per-item expiration
 * lookups (e.g. earliest-expiration subqueries and per-item ordering),
 * while the (expires_at) index supports global expiration scans.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->index(['item_id', 'expires_at'], 'batches_item_id_expires_at_index');
            $table->index('expires_at', 'batches_expires_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            // The (item_id, expires_at) index is the only index backing the item_id
            // foreign key, so the FK must be dropped before the index can be removed.
            $table->dropIndex('batches_expires_at_index');
            $table->dropForeign(['item_id']);
            $table->dropIndex('batches_item_id_expires_at_index');
            $table->foreign('item_id')
                ->references('id')
                ->on('items')
                ->onDelete('cascade');
        });
    }
};
