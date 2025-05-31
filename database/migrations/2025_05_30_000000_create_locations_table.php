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
        Schema::create('locations', function (Blueprint $table) {
            $table->uuid('id')
                ->primary();
            $table->string('name');
            $table->string('description')
                ->nullable();
            $table->unsignedInteger('expiration_notify_days')
                ->default(0);
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
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
