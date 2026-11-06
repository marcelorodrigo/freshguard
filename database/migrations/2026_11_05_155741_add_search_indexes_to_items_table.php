<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds indexes supporting Quick Consume's tiered search.
 *
 * Exact barcode matches and prefix matches on name, barcode, and description
 * can use these B-tree indexes. Only the substring fallback remains a scan.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->index('barcode', 'items_barcode_index');
            $table->index('name', 'items_name_index');
            $table->index('description', 'items_description_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex('items_barcode_index');
            $table->dropIndex('items_name_index');
            $table->dropIndex('items_description_index');
        });
    }
};
