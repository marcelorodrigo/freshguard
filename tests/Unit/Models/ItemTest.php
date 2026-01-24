<?php

namespace Tests\Unit\Models;

use App\Models\Batch;
use App\Models\Item;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('uuid is generated on creation', function () {
    $item = Item::factory()->create();

    expect($item->id)->not->toBeNull()
        ->and(Str::isUuid($item->id))->toBeTrue();
});

test('fillable attributes', function () {
    $item = new Item;

    expect($item->getFillable())->toBe([
        'name',
        'barcode',
        'description',
        'tags',
        'quantity',
    ]);
});

test('incrementing is false', function () {
    $item = new Item;

    expect($item->getIncrementing())->toBeFalse();
});

test('key type is string', function () {
    $item = new Item;

    expect($item->getKeyType())->toBe('string');
});

test('factory creates valid item', function () {
    $item = Item::factory()->create();
    $this->assertDatabaseHas('items', [
        'id' => $item->id,
        'name' => $item->name,
        'quantity' => 0, // Default quantity
    ]);
});

test('factory creates item with optional description', function () {
    // Create item with description
    $itemWithDescription = Item::factory()->create();
    expect($itemWithDescription->description)->not->toBeNull();

    // Create item without description
    $itemWithoutDescription = Item::factory()->withoutDescription()->create();
    expect($itemWithoutDescription->description)->toBeNull();
});

test('creates with minimal attributes', function () {
    $item = Item::create([
        'name' => 'Test Item',
    ]);

    $this->assertDatabaseHas('items', [
        'id' => $item->id,
        'name' => 'Test Item',
    ]);
    expect($item->description)->toBeNull();
});

test('updates attributes', function () {
    $item = Item::factory()->create();
    $originalId = $item->id;

    $item->update([
        'name' => 'Updated Name',
        'description' => 'Updated Description',
    ]);

    expect($item->id)->toBe($originalId)
        ->and($item->name)->toBe('Updated Name')
        ->and($item->description)->toBe('Updated Description');

    $this->assertDatabaseHas('items', [
        'id' => $originalId,
        'name' => 'Updated Name',
        'description' => 'Updated Description',
    ]);
});

test('deletes item', function () {
    $item = Item::factory()->create();
    $itemId = $item->id;

    $item->delete();

    $this->assertDatabaseMissing('items', [
        'id' => $itemId,
    ]);
});


test('has batches relationship', function () {
    $item = new Item;

    expect($item->batches())->toBeInstanceOf(HasMany::class)
        ->and($item->batches)->toBeInstanceOf(Collection::class);
});

test('can have many batches', function () {
    $item = Item::factory()->create();
    $batches = Batch::factory()->count(3)->for($item)->create();

    expect($item->batches)->toHaveCount(3);
    foreach ($batches as $batch) {
        expect($item->batches->contains($batch))->toBeTrue();
    }
});

test('scope with batches expiring within days includes items with batches expiring in range', function () {
    // Set fixed date for testing
    Carbon::setTestNow('2025-06-11 17:00:00');

    // Create items with batches expiring at different times
    $itemWithBatchExpiringToday = Item::factory()->create();
    $itemWithBatchExpiringIn3Days = Item::factory()->create();
    $itemWithBatchExpiringIn7Days = Item::factory()->create();
    $itemWithBatchExpiringIn10Days = Item::factory()->create();
    $itemWithExpiredBatch = Item::factory()->create();
    $itemWithNoExpiringBatchInRange = Item::factory()->create();

    // Create batches with specific expiration dates
    Batch::factory()->for($itemWithBatchExpiringToday)->create(['expires_at' => Carbon::now()]);
    Batch::factory()->for($itemWithBatchExpiringIn3Days)->create(['expires_at' => Carbon::now()->addDays(3)]);
    Batch::factory()->for($itemWithBatchExpiringIn7Days)->create(['expires_at' => Carbon::now()->addDays(7)]);
    Batch::factory()->for($itemWithBatchExpiringIn10Days)->create(['expires_at' => Carbon::now()->addDays(10)]);
    Batch::factory()->for($itemWithExpiredBatch)->create(['expires_at' => Carbon::now()->subDays(1)]);
    Batch::factory()->for($itemWithNoExpiringBatchInRange)->create(['expires_at' => Carbon::now()->addDays(15)]);

    // Test scope with 5 days range
    $itemsExpiringWithin5Days = Item::withBatchesExpiringWithinDays(5)->get();

    // Should include items with batches expiring today, in 3 days, but not those expiring in 7 or 10 days
    expect($itemsExpiringWithin5Days->contains($itemWithBatchExpiringToday))->toBeTrue()
        ->and($itemsExpiringWithin5Days->contains($itemWithBatchExpiringIn3Days))->toBeTrue()
        ->and($itemsExpiringWithin5Days->contains($itemWithBatchExpiringIn7Days))->toBeFalse()
        ->and($itemsExpiringWithin5Days->contains($itemWithBatchExpiringIn10Days))->toBeFalse()
        ->and($itemsExpiringWithin5Days->contains($itemWithExpiredBatch))->toBeFalse()
        ->and($itemsExpiringWithin5Days->contains($itemWithNoExpiringBatchInRange))->toBeFalse();
});

test('scope with batches expiring within days works with multiple batches', function () {
    // Set fixed date for testing
    Carbon::setTestNow('2025-06-11 18:00:00');

    // Create item with multiple batches
    $item = Item::factory()->create();

    // One batch expires in range, one doesn't
    Batch::factory()->for($item)->create(['expires_at' => Carbon::now()->addDays(3)]);
    Batch::factory()->for($item)->create(['expires_at' => Carbon::now()->addDays(10)]);

    // Item should be included in results because at least one batch expires within range
    $itemsExpiringWithin5Days = Item::withBatchesExpiringWithinDays(5)->get();

    expect($itemsExpiringWithin5Days->contains($item))->toBeTrue();
});

test('scope with batches expiring within days returns empty collection when no matches', function () {
    // Set fixed date for testing
    Carbon::setTestNow('2025-05-30 12:00:00');

    // Create items with batches that don't expire within the range
    $item1 = Item::factory()->create();
    $item2 = Item::factory()->create();

    Batch::factory()->for($item1)->create(['expires_at' => Carbon::now()->addDays(10)]);
    Batch::factory()->for($item2)->create(['expires_at' => Carbon::now()->subDays(1)]);

    // Test scope with 5 days range
    $itemsExpiringWithin5Days = Item::withBatchesExpiringWithinDays(5)->get();

    expect($itemsExpiringWithin5Days)->toHaveCount(0);
});

test('scope with batches expiring within days excludes items without batches', function () {
    // Create item without batches
    $itemWithoutBatches = Item::factory()->create();

    // Test scope with any days range
    $itemsExpiringWithinDays = Item::withBatchesExpiringWithinDays(30)->get();

    expect($itemsExpiringWithinDays->contains($itemWithoutBatches))->toBeFalse();
});
