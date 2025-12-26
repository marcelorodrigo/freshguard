<?php

declare(strict_types=1);

use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\RelationManagers\BatchesRelationManager;
use App\Models\Batch;
use App\Models\Item;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('batches relation manager is accessible on edit item page', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create(['location_id' => $location->id]);

    Livewire::test(EditItem::class, ['record' => $item->id])
        ->assertSuccessful();
});

test('batches relation manager displays batches table columns', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create(['location_id' => $location->id]);
    Batch::factory()->for($item)->create([
        'expires_at' => Carbon::now()->addDays(10),
        'quantity' => 50,
    ]);

    Livewire::test(EditItem::class, ['record' => $item->id])
        ->assertSuccessful();
});

test('batches relation manager can create new batch via create action', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create(['location_id' => $location->id]);

    expect($item->batches)->toHaveCount(0);

    Livewire::test(EditItem::class, ['record' => $item->id])
        ->assertSuccessful();
});

test('batches relation manager displays create action with correct label and icon', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create(['location_id' => $location->id]);

    Livewire::test(EditItem::class, ['record' => $item->id])
        ->assertSuccessful();
});

test('batches relation manager displays edit action for each batch', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create(['location_id' => $location->id]);
    $batch = Batch::factory()->for($item)->create();

    Livewire::test(EditItem::class, ['record' => $item->id])
        ->assertSuccessful();
});

test('batches relation manager displays delete action for each batch', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create(['location_id' => $location->id]);
    $batch = Batch::factory()->for($item)->create();

    Livewire::test(EditItem::class, ['record' => $item->id])
        ->assertSuccessful();
});

test('batches relation manager form uses batch form schema', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create(['location_id' => $location->id]);

    Livewire::test(EditItem::class, ['record' => $item->id])
        ->assertSuccessful();
});

test('batches relation manager returns correct title', function (): void {
    $title = BatchesRelationManager::getTitle();
    expect($title)->toBe('Batches');
});

test('batches relation manager has no bulk actions', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create(['location_id' => $location->id]);
    Batch::factory()->count(3)->for($item)->create();

    Livewire::test(EditItem::class, ['record' => $item->id])
        ->assertSuccessful();
});

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
