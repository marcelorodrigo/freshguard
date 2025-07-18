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
        Schema::create('items', function (Blueprint $table) {
            $table->uuid('id')
                ->primary();
            // When a location is deleted, all items that belong to that location will be automatically deleted
            $table->foreignUuid('location_id')
                ->constrained()
                ->onDelete('cascade');
            $table->string('name', 255);
            $table->string('description', 255)
                ->nullable();
            $table->unsignedInteger('quantity')
                ->default(0);
            $table->unsignedInteger('expiration_notify_days')
                ->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
