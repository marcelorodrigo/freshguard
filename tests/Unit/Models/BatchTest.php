<?php

namespace Tests\Unit\Models;

use App\Models\Batch;
use App\Models\Item;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('it uses uuids as primary key', function () {
    $batch = Batch::factory()->create();
    expect($batch->id)->toBeString()
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

test('it belongs to an item', function () {
    $batch = Batch::factory()->create();

    expect($batch->item())->toBeInstanceOf(BelongsTo::class)
        ->and($batch->item)->toBeInstanceOf(Item::class);
});

test('it has correct fillable attributes', function () {
    $expected = [
        'item_id',
        'expires_at',
        'quantity',
    ];

    expect(new Batch()->getFillable())->toBe($expected);
});

test('it casts attributes correctly', function () {
    $batch = Batch::factory()->create();

    expect($batch->item_id)->toBeString()
        ->and($batch->expires_at)->toBeInstanceOf(Carbon::class)
        ->and($batch->quantity)->toBeInt();
});

test('it creates valid factory instances', function () {
    $batch = Batch::factory()->create();

    $this->assertDatabaseHas('batches', [
        'id' => $batch->id,
    ]);

    expect($batch->item_id)->not->toBeNull()
        ->and($batch->expires_at)->not->toBeNull()
        ->and($batch->quantity)->not->toBeNull();
});

test('it can create batches with custom attributes', function () {
    $item = Item::factory()->create();
    $expiresAt = now()->addDays(30);

    $batch = Batch::factory()->create([
        'item_id' => $item->id,
        'expires_at' => $expiresAt,
        'quantity' => 42,
    ]);

    expect($batch->item_id)->toBe($item->id)
        ->and($batch->expires_at->toDateTimeString())->toBe($expiresAt->toDateTimeString())
        ->and($batch->quantity)->toBe(42);

    $item->refresh();
    expect($item->quantity)->toBe(42);
});

test('it can find batches by item', function () {
    // Create item with multiple batches
    $item = Item::factory()->create();
    Batch::factory()->count(3)->create([
        'item_id' => $item->id,
    ]);

    // Create another item with batches to ensure filtering works
    $anotherItem = Item::factory()->create();
    Batch::factory()->count(2)->create([
        'item_id' => $anotherItem->id,
    ]);

    // Test relationship
    expect($item->batches)->toHaveCount(3)
        ->and($anotherItem->batches)->toHaveCount(2);
});

test('deleting batch updates item quantity', function () {
    // Create an item
    $item = Item::factory()->create();

    // Create three batches with specific quantities
    $batch10 = Batch::factory()->create([
        'item_id' => $item->id,
        'quantity' => 10,
    ]);

    $batch20 = Batch::factory()->create([
        'item_id' => $item->id,
        'quantity' => 20,
    ]);

    $batch30 = Batch::factory()->create([
        'item_id' => $item->id,
        'quantity' => 30,
    ]);

    // Refresh the item to get updated quantity
    $item->refresh();

    // Verify total quantity is the sum of all three batches
    expect($item->quantity)->toBe(60);

    // Delete the batch with quantity 20
    $batch20->delete();

    // Refresh the item to get updated quantity
    $item->refresh();

    // Verify item quantity is now 40 (10 + 30)
    expect($item->quantity)->toBe(40);
});
