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
        // Step 1.3 Finalize schema changes and clean up
        // 1. Drop FK constraint on batches.location_id
        Schema::table('batches', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
        });
        // 2. Make location_id on batches NOT NULL
        Schema::table('batches', function (Blueprint $table) {
            $table->uuid('location_id')->nullable(false)->change();
        });
        // 3. Re-add FK constraint (CASCADE on delete for now)
        Schema::table('batches', function (Blueprint $table) {
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('cascade');
        });
        // 4. Add unique constraint for (item_id, location_id, expires_at)
        Schema::table('batches', function (Blueprint $table) {
            $table->unique(['item_id', 'location_id', 'expires_at'], 'batches_item_location_exp_unique');
        });
        // 3. Remove foreign key and column items.location_id
        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            //
        });
    }
};
