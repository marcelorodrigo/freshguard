<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the `locations` table with hierarchical support.
     *
     * Defines a table with a UUID primary key, name, optional description, and an optional self-referential `parent_id` foreign key that allows for hierarchical location relationships. If a parent location is deleted, child records have their `parent_id` set to null. Includes timestamp columns.
     */
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->uuid('id')
                ->primary();
            $table->string('name');
            $table->string('description')
                ->nullable();
            // Nullable foreign key to allow root locations without a parent
            $table->uuid('parent_id')
                ->nullable();
            // and to allow deletion of parent locations without deleting children.
            $table->foreign('parent_id')
                ->references('id')
                ->on('locations')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Drops the `locations` table from the database if it exists.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
