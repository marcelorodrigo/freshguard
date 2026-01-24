<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the 'location_id' field from 'items' to 'batches'.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->foreignUuid('location_id')
                ->nullable()
                ->after('item_id');
        });

        // Migrate location_id data from items to batches
        DB::transaction(function () {
            DB::table('batches')
                ->join('items', 'batches.item_id', '=', 'items.id')
                ->update(['batches.location_id' => DB::raw('items.location_id')]);
        });

        // Make location_id NOT NULL after migration
        Schema::table('batches', function (Blueprint $table) {
            $table->foreignUuid('location_id')
                ->nullable(false)
                ->change();
        });

        // Drop location_id from items table
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add location_id back to items table as nullable
        Schema::table('items', function (Blueprint $table) {
            $table->foreignUuid('location_id')
                ->nullable()
                ->after('id');
        });

        // Reverse: move location_id from batches back to items
        DB::transaction(function () {
            DB::table('items')
                ->join('batches', 'items.id', '=', 'batches.item_id')
                ->whereNotNull('batches.location_id')
                ->update(['items.location_id' => DB::raw('batches.location_id')]);
        });

        // Make location_id NOT NULL after restoring data
        Schema::table('items', function (Blueprint $table) {
            $table->foreignUuid('location_id')
                ->nullable(false)
                ->change();
        });

        // Drop location_id from batches table
        Schema::table('batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });
    }
};
