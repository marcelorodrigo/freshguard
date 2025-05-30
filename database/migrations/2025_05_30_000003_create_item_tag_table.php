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
        Schema::create('item_tag', function (Blueprint $table) {
            // When an item is deleted, all its relationships in the item_tag table will be automatically removed
            $table->foreignUuid('item_id')
                ->constrained()
                ->onDelete('cascade');
            // When a tag is deleted, all its relationships in the item_tag table will be automatically removed
            $table->foreignUuid('tag_id')
                ->constrained()
                ->onDelete('cascade');
            $table->primary(['item_id', 'tag_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_tag');
    }
};
