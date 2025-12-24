<?php
declare(strict_types=1);

use App\Filament\Resources\Locations\Pages\ManageLocations;
use App\Models\Location;
use Livewire\Livewire;


test('can load locations with created records', function (): void {
    $locations_count = 5;
    $parent = Location::factory()->create(['name' => 'Parent Location']);
    $locations = Location::factory()
        ->count($locations_count)
        ->sequence(
            ['parent_id' => $parent->id],
            ['parent_id' => null]
        )
        ->create();

    Livewire::test(ManageLocations::class)
        ->assertOk()
        ->assertCanSeeTableRecords($locations)
        ->assertCountTableRecords($locations_count + 1)
        ->assertCanRenderTableColumn('name')
        ->assertCanRenderTableColumn('description')
        ->assertCanRenderTableColumn('parent.name');
});

test('can create location', function (): void {
    $location = Location::factory()->make();

    Livewire::test(ManageLocations::class)
        ->callAction('create', data: [
            'name' => $location->name,
            'description' => $location->description,
            'expiration_notify_days' => $location->expiration_notify_days,
            'parent_id' => null
        ])
        ->assertOk()
        ->assertNotified();

    $this->assertDatabaseHas(Location::class, [
        'name' => $location->name,
        'description' => $location->description,
        'expiration_notify_days' => $location->expiration_notify_days,
        'parent_id' => null
    ]);
});

test('can edit location', function (): void {
    $location = Location::factory()->create([
        'name' => 'Original Name',
        'description' => 'Original Description',
        'expiration_notify_days' => 10,
        'parent_id' => null
    ]);

    $newData = [
        'name' => 'Updated Name',
        'description' => 'Updated Description',
        'expiration_notify_days' => 20,
        'parent_id' => null
    ];

    Livewire::test(ManageLocations::class)
        ->callTableAction('edit', $location, data: $newData)
        ->assertNotified();

    $this->assertDatabaseHas(Location::class, [
        'id' => $location->id,
        ...$newData
    ]);
});
