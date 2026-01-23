<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            // Step 1.1: Add nullable location_id and index
            $table->foreignUuid('location_id')->nullable()->constrained()->nullOnDelete();
            // Add composite index for performance; unique constraint will follow in a later migration
            $table->index(['item_id', 'location_id', 'expires_at'], 'batches_item_location_exp_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            // Step 1.1: Drop the FK and column for location_id
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
            $table->dropIndex('batches_item_location_exp_index');
        });
    }
};
