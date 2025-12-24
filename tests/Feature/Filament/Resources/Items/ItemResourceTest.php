<?php

declare(strict_types=1);

use App\Filament\Resources\Items\Pages\ManageItems;
use App\Models\Item;
use App\Models\Location;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('can load items with created records', function (): void {
    $location = Location::factory()->create();
    $items = Item::factory()
        ->count(5)
        ->sequence(
            ['location_id' => $location->id]
        )
        ->create();

    Livewire::test(ManageItems::class)
        ->assertOk()
        ->assertCanSeeTableRecords($items)
        ->assertCountTableRecords(5)
        ->assertCanRenderTableColumn('name')
        ->assertCanRenderTableColumn('location.name')
        ->assertCanRenderTableColumn('quantity');
});

test('can create item', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->make();

    Livewire::test(ManageItems::class)
        ->callAction('create', data: [
            'name' => $item->name,
            'description' => $item->description,
            'location_id' => $location->id,
            'quantity' => 0,
            'expiration_notify_days' => $item->expiration_notify_days,
            'tags' => [],
        ])
        ->assertOk()
        ->assertNotified();

    $this->assertDatabaseHas(Item::class, [
        'name' => $item->name,
        'description' => $item->description,
        'location_id' => $location->id,
        'expiration_notify_days' => $item->expiration_notify_days,
    ]);
});

test('can edit item', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create([
        'location_id' => $location->id,
        'name' => 'Original Item',
        'description' => 'Original Description',
        'expiration_notify_days' => 10,
    ]);

    $newData = [
        'name' => 'Updated Item',
        'description' => 'Updated Description',
        'location_id' => $location->id,
        'quantity' => 0,
        'expiration_notify_days' => 20,
        'tags' => [],
    ];

    Livewire::test(ManageItems::class)
        ->callTableAction('edit', $item, data: $newData)
        ->assertNotified();

    $this->assertDatabaseHas(Item::class, [
        'id' => $item->id,
        'name' => 'Updated Item',
        'description' => 'Updated Description',
        'expiration_notify_days' => 20,
    ]);
});

test('can assign tags to item', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create(['location_id' => $location->id]);
    $tags = Tag::factory()->count(3)->create();

    Livewire::test(ManageItems::class)
        ->callTableAction('edit', $item, data: [
            'name' => $item->name,
            'description' => $item->description,
            'location_id' => $location->id,
            'quantity' => 0,
            'expiration_notify_days' => $item->expiration_notify_days,
            'tags' => $tags->pluck('id')->toArray(),
        ])
        ->assertNotified();

    foreach ($tags as $tag) {
        $this->assertDatabaseHas('item_tag', [
            'item_id' => $item->id,
            'tag_id' => $tag->id,
        ]);
    }
});

