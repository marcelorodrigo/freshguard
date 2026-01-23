<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @throws Throwable
     */
    public function up(): void
    {
        // Step 1.2 Populating batches.location_id and merging duplicates
        // Use DB and models for correct relationships
        DB::transaction(function () {
            // (1) Populate batches.location_id from parent item's location_id
            DB::table('batches')->whereNull('location_id')->chunkById(100, function ($batches) {
                foreach ($batches as $batch) {
                    $itemLocationId = DB::table('items')->where('id', $batch->item_id)->value('location_id');
                    if ($itemLocationId) {
                        DB::table('batches')->where('id', $batch->id)->update(['location_id' => $itemLocationId]);
                    }
                }
            });

            // (2) Merge duplicates for (item_id, location_id, expires_at): sum quantities and keep one batch
            $duplicates = DB::table('batches')
                ->select('item_id', 'location_id', 'expires_at', DB::raw('count(*) as count'))
                ->groupBy('item_id', 'location_id', 'expires_at')
                ->having('count', '>', 1)
                ->get();

            foreach ($duplicates as $dup) {
                $all = \DB::table('batches')
                    ->where('item_id', $dup->item_id)
                    ->where('location_id', $dup->location_id)
                    ->where('expires_at', $dup->expires_at)
                    ->get();
                $ids = $all->pluck('id');
                $sum = $all->sum('quantity');
                $keepId = $ids->first();
                DB::table('batches')->where('id', $keepId)->update(['quantity' => $sum]);
                DB::table('batches')->whereIn('id', $ids->slice(1)->all())->delete();
            }
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
};w
