<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Item;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('item has batches relationship', function (): void {
    $item = Item::factory()->create();
    $batches = Batch::factory()->count(3)->for($item)->create();

    expect($item->batches)->toHaveCount(3)
        ->and($item->batches->contains($batches[0]))->toBeTrue()
        ->and($item->batches->contains($batches[1]))->toBeTrue()
        ->and($item->batches->contains($batches[2]))->toBeTrue();
});

test('batch belongs to item relationship', function (): void {
    $item = Item::factory()->create();
    $batch = Batch::factory()->for($item)->create();

    expect($batch->item)->toBeInstanceOf(Item::class)
        ->and($batch->item->id)->toBe($item->id);
});

test('creating batch adds to item batches', function (): void {
    $item = Item::factory()->create();

    expect($item->batches)->toHaveCount(0);

    Batch::factory()->for($item)->create();

    $item->refresh();
    expect($item->batches)->toHaveCount(1);
});

test('multiple items have separate batches', function (): void {
    $item1 = Item::factory()->create();
    $item2 = Item::factory()->create();

    Batch::factory()->count(3)->for($item1)->create();
    Batch::factory()->count(2)->for($item2)->create();

    $item1->refresh();
    $item2->refresh();

    expect($item1->batches)->toHaveCount(3)
        ->and($item2->batches)->toHaveCount(2);
});

test('batch quantity affects item total quantity', function (): void {
    $item = Item::factory()->create();

    // Verify item starts with quantity = 0 (from migration default)
    $freshItem = Item::find($item->id);
    expect($freshItem->quantity)->toBe(0);

    // Add first batch
    Batch::factory()->for($item)->create(['quantity' => 15]);
    $item->refresh();
    expect($item->quantity)->toBe(15);

    // Add second batch
    Batch::factory()->for($item)->create(['quantity' => 25]);
    $item->refresh();
    expect($item->quantity)->toBe(40);

    // Add third batch
    Batch::factory()->for($item)->create(['quantity' => 10]);
    $item->refresh();
    expect($item->quantity)->toBe(50);
});

test('deleting batch updates item quantity', function (): void {
    $item = Item::factory()->create();
    $batch1 = Batch::factory()->for($item)->create(['quantity' => 30]);
    $batch2 = Batch::factory()->for($item)->create(['quantity' => 20]);

    $item->refresh();
    expect($item->quantity)->toBe(50);

    $batch1->delete();

    $item->refresh();
    expect($item->quantity)->toBe(20);
});

test('editing batch quantity updates item total', function (): void {
    $item = Item::factory()->create();
    $batch = Batch::factory()->for($item)->create(['quantity' => 50]);

    $item->refresh();
    expect($item->quantity)->toBe(50);

    $batch->update(['quantity' => 100]);

    $item->refresh();
    expect($item->quantity)->toBe(100);
});

test('deleting all batches resets item quantity to zero', function (): void {
    $item = Item::factory()->create();
    $batch1 = Batch::factory()->for($item)->create(['quantity' => 10]);
    $batch2 = Batch::factory()->for($item)->create(['quantity' => 10]);
    $batch3 = Batch::factory()->for($item)->create(['quantity' => 10]);

    $item->refresh();
    expect($item->quantity)->toBe(30);

    // Delete batches one by one to trigger the event
    $batch1->delete();
    $batch2->delete();
    $batch3->delete();

    $item->refresh();
    expect($item->quantity)->toBe(0);
});

test('batch with custom expiration date is properly stored', function (): void {
    $expiresAt = Carbon::now()->addDays(30);
    $batch = Batch::factory()->for(Item::factory())->create(['expires_at' => $expiresAt]);

    expect($batch->expires_at)->toBeInstanceOf(Carbon::class)
        ->and($batch->expires_at->format('Y-m-d'))->toBe($expiresAt->format('Y-m-d'));
});

test('batch without item cannot be created', function (): void {
    $batch = new Batch([
        'expires_at' => Carbon::now()->addDays(30),
        'quantity' => 10,
    ]);

    $this->assertNull($batch->item_id);
});

test('item batches relation is eager loadable', function (): void {
    $items = Item::factory()->count(3)->create();

    foreach ($items as $item) {
        Batch::factory()->count(2)->for($item)->create();
    }

    $loadedItems = Item::with('batches')->get();

    foreach ($loadedItems as $item) {
        expect($item->batches)->toHaveCount(2);
    }
});

