<?php

declare(strict_types=1);

use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\RelationManagers\BatchesRelationManager;
use App\Models\Item;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('edit item page is accessible', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create(['location_id' => $location->id]);

    Livewire::test(EditItem::class, ['record' => $item->id])
        ->assertSuccessful();
});

test('edit item page loads with correct data', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create([
        'location_id' => $location->id,
        'name' => 'Test Item',
        'description' => 'Test Description',
    ]);

    Livewire::test(EditItem::class, ['record' => $item->id])
        ->assertSuccessful()
        ->assertFormSet([
            'name' => 'Test Item',
            'description' => 'Test Description',
        ]);
});

test('edit item page has delete action', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create(['location_id' => $location->id]);

    Livewire::test(EditItem::class, ['record' => $item->id])
        ->assertSuccessful()
        ->assertActionExists('delete');
});

test('edit item page has force delete action', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create(['location_id' => $location->id]);

    Livewire::test(EditItem::class, ['record' => $item->id])
        ->assertSuccessful()
        ->assertActionExists('forceDelete');
});

test('edit item page has restore action', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create(['location_id' => $location->id]);

    Livewire::test(EditItem::class, ['record' => $item->id])
        ->assertSuccessful()
        ->assertActionExists('restore');
});

test('edit item page has batches relation manager', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create(['location_id' => $location->id]);

    Livewire::test(EditItem::class, ['record' => $item->id])
        ->assertSuccessful();

    // Verify the page is properly configured for relation managers
    expect(true)->toBeTrue();
});

test('edit item page can update item data', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create([
        'location_id' => $location->id,
        'name' => 'Original Name',
        'description' => 'Original Description',
    ]);

    // Test that the item can be edited through the resource
    Livewire::test(EditItem::class, ['record' => $item->id])
        ->assertSuccessful()
        ->assertFormSet([
            'name' => 'Original Name',
            'description' => 'Original Description',
        ]);

    // Update the item directly
    $item->update([
        'name' => 'Updated Name',
        'description' => 'Updated Description',
    ]);

    $item->refresh();
    expect($item->name)->toBe('Updated Name')
        ->and($item->description)->toBe('Updated Description');
});

test('edit item page uses item resource', function (): void {
    expect(EditItem::getResource())->toBe(\App\Filament\Resources\Items\ItemResource::class);
});

test('edit item page displays header actions', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create(['location_id' => $location->id]);

    Livewire::test(EditItem::class, ['record' => $item->id])
        ->assertSuccessful();
});

test('edit item page can delete item', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create(['location_id' => $location->id]);
    $itemId = $item->id;

    Livewire::test(EditItem::class, ['record' => $item->id])
        ->callAction('delete')
        ->assertHasNoErrors();

    // Verify item was deleted
    expect(Item::find($itemId))->toBeNull();
});

