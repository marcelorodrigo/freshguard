<?php

declare(strict_types=1);

use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\RelationManagers\BatchesRelationManager;
use App\Models\Batch;
use App\Models\Item;
use App\Models\Location;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

test('can render relation manager with batches', function (): void {
    $item = Item::factory()->create();
    $batches = Batch::factory()->count(3)->for($item)->create();

    livewire(BatchesRelationManager::class, [
        'ownerRecord' => $item,
        'pageClass' => EditItem::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords($batches)
        ->assertCountTableRecords(3);
});

test('can render table columns', function (): void {
    $item = Item::factory()->create();
    Batch::factory()->for($item)->create();

    livewire(BatchesRelationManager::class, [
        'ownerRecord' => $item,
        'pageClass' => EditItem::class,
    ])
        ->assertCanRenderTableColumn('expires_at')
        ->assertCanRenderTableColumn('location.name')
        ->assertCanRenderTableColumn('quantity');
});

test('can create batch', function (): void {
    $item = Item::factory()->create();
    $expiresAt = Carbon::now()->addDays(30);
    $location = Location::factory()->create();

    livewire(BatchesRelationManager::class, [
        'ownerRecord' => $item,
        'pageClass' => EditItem::class,
    ])
        ->callAction(TestAction::make(CreateAction::class)->table(), [
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'quantity' => 50,
            'location_id' => $location->id,
        ])
        ->assertNotified();

    $this->assertDatabaseHas(Batch::class, [
        'item_id' => $item->id,
        'quantity' => 50,
    ]);
});

test('can edit batch', function (): void {
    $item = Item::factory()->create();
    $batch = Batch::factory()->for($item)->create([
        'quantity' => 50,
        'expires_at' => Carbon::now()->addDays(10),
    ]);

    $newExpiresAt = Carbon::now()->addDays(20);

    livewire(BatchesRelationManager::class, [
        'ownerRecord' => $item,
        'pageClass' => EditItem::class,
    ])
        ->callTableAction(EditAction::class, $batch, data: [
            'quantity' => 100,
            'expires_at' => $newExpiresAt->format('Y-m-d H:i:s'),
        ])
        ->assertNotified();

    $this->assertDatabaseHas(Batch::class, [
        'id' => $batch->id,
        'quantity' => 100,
    ]);
});

test('can delete batch', function (): void {
    $item = Item::factory()->create();
    $batch = Batch::factory()->for($item)->create();

    livewire(BatchesRelationManager::class, [
        'ownerRecord' => $item,
        'pageClass' => EditItem::class,
    ])
        ->callTableAction(DeleteAction::class, $batch)
        ->assertNotified();

    expect(Batch::find($batch->id))->toBeNull();
});

test('can sort batches by expires_at', function (): void {
    $item = Item::factory()->create();
    $batch1 = Batch::factory()->for($item)->create(['expires_at' => Carbon::now()->addDays(5)]);
    $batch2 = Batch::factory()->for($item)->create(['expires_at' => Carbon::now()->addDays(10)]);
    $batch3 = Batch::factory()->for($item)->create(['expires_at' => Carbon::now()->addDays(3)]);

    $sortedBatches = Batch::query()->where('item_id', $item->id)->orderBy('expires_at')->get();

    livewire(BatchesRelationManager::class, [
        'ownerRecord' => $item,
        'pageClass' => EditItem::class,
    ])
        ->sortTable('expires_at')
        ->assertCanSeeTableRecords($sortedBatches, inOrder: true);
});
