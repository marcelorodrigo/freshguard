<?php

namespace Tests\Unit\Models;

use App\Models\Batch;
use App\Models\Item;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        'quantity' => 0, // Default is now 0 until batches are added or updated
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

// ---- Tests below this line were removed as the application no longer has the 'withBatchesExpiringWithinDays' scope ----
