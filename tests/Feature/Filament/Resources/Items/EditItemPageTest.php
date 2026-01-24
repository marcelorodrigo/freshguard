<?php

declare(strict_types=1);

use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\RelationManagers\BatchesRelationManager;
use App\Models\Item;
use App\Models\Location;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

test('can load page with correct form data', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create([

        'name' => 'Test Item',
        'barcode' => '1234567890123',
        'description' => 'Test Description',
        'tags' => ['Tag1', 'Tag2'],
    ]);

    livewire(EditItem::class, ['record' => $item->id])
        ->assertSuccessful()
        ->assertSchemaStateSet([
            'name' => 'Test Item',
            'barcode' => '1234567890123',
            'description' => 'Test Description',

            'tags' => ['Tag1', 'Tag2'],
        ]);
});

test('can update item', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create([

        'name' => 'Original Name',
        'barcode' => '1234567890123',
        'description' => 'Original Description',
    ]);

    $newLocation = Location::factory()->create();

    livewire(EditItem::class, ['record' => $item->id])
        ->fillForm([
            'name' => 'Updated Name',
            'barcode' => '1234567890123',
            'description' => 'Updated Description',

        ])
        ->call('save')
        ->assertNotified();

    $this->assertDatabaseHas(Item::class, [
        'id' => $item->id,
        'name' => 'Updated Name',
        'description' => 'Updated Description',

    ]);
});

test('can delete item', function (): void {
    $item = Item::factory()->create();

    livewire(EditItem::class, ['record' => $item->id])
        ->callAction(DeleteAction::class)
        ->assertNotified();

    expect(Item::find($item->id))->toBeNull();
});

test('has batches relation manager', function (): void {
    $item = Item::factory()->create();

    livewire(EditItem::class, ['record' => $item->id])
        ->assertSuccessful()
        ->assertSeeLivewire(BatchesRelationManager::class);
});
