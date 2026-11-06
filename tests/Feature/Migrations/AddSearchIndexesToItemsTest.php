<?php

declare(strict_types=1);

use App\Models\Item;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @param  array<int, mixed>  $bindings
 * @return array<int, stdClass>
 */
function explainItemSearchQuery(string $sql, array $bindings): array
{
    /** @var array<int, stdClass> $result */
    $result = DB::select($sql, $bindings);

    return $result;
}

uses(LazilyRefreshDatabase::class);

$searchIndexes = [
    'items_barcode_index',
    'items_name_index',
    'items_description_index',
];

test('migration adds the search indexes', function () use ($searchIndexes): void {
    $existing = collect(DB::select('SHOW INDEXES FROM items'))
        ->pluck('Key_name')
        ->unique();

    foreach ($searchIndexes as $index) {
        expect($existing)->toContain($index);
    }
});

test('search tiers use the intended indexes', function (): void {
    foreach (range(1, 200) as $index) {
        Item::factory()->create([
            'name' => sprintf('Product %03d', $index),
            'barcode' => sprintf('12345%08d', $index),
            'description' => sprintf('Description %03d', $index),
        ]);
    }

    $positiveStock = 'EXISTS (SELECT 1 FROM batches WHERE batches.item_id = items.id AND batches.quantity > 0)';

    $barcodePlan = explainItemSearchQuery(
        "EXPLAIN SELECT id FROM items WHERE barcode = ? AND {$positiveStock}",
        ['1234500000010'],
    );

    expect($barcodePlan[0]->key)->toBe('items_barcode_index');

    $namePlan = explainItemSearchQuery(
        "EXPLAIN SELECT id FROM items WHERE name LIKE ? ESCAPE ? AND {$positiveStock}",
        ['Product 001%', '\\'],
    );

    expect($namePlan[0]->possible_keys)->toContain('items_name_index');

    $descriptionPlan = explainItemSearchQuery(
        "EXPLAIN SELECT id FROM items WHERE description LIKE ? ESCAPE ? AND {$positiveStock}",
        ['Description 001%', '\\'],
    );

    expect($descriptionPlan[0]->possible_keys)->toContain('items_description_index');
});

test('search indexes are reversible', function () use ($searchIndexes): void {
    Schema::table('items', function (Blueprint $table): void {
        $table->dropIndex('items_barcode_index');
        $table->dropIndex('items_name_index');
        $table->dropIndex('items_description_index');
    });

    $afterDrop = collect(DB::select('SHOW INDEXES FROM items'))
        ->pluck('Key_name')
        ->unique();

    foreach ($searchIndexes as $index) {
        expect($afterDrop)->not->toContain($index);
    }

    Schema::table('items', function (Blueprint $table): void {
        $table->index('barcode', 'items_barcode_index');
        $table->index('name', 'items_name_index');
        $table->index('description', 'items_description_index');
    });

    $afterReAdd = collect(DB::select('SHOW INDEXES FROM items'))
        ->pluck('Key_name')
        ->unique();

    foreach ($searchIndexes as $index) {
        expect($afterReAdd)->toContain($index);
    }
});
