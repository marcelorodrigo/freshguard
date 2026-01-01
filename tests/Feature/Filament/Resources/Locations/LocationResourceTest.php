<?php

declare(strict_types=1);

use App\Filament\Resources\Locations\Pages\ManageLocations;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

test('can render page and see table records', function (): void {
    $parent = Location::factory()->create(['name' => 'Parent Location']);
    $locations = Location::factory()->count(5)->create(['parent_id' => $parent->id]);

    livewire(ManageLocations::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($locations)
        ->assertCountTableRecords(6) // 5 + parent
        ->assertCanRenderTableColumn('name')
        ->assertCanRenderTableColumn('description')
        ->assertCanRenderTableColumn('parent.name');
});

test('can search locations by name', function (): void {
    Location::query()->delete();
    $locations = collect(range(1, 5))->map(function ($i) {
        return Location::factory()->create(['name' => 'Test Location '.$i]);
    });
    $searchLocation = $locations->first();

    livewire(ManageLocations::class)
        ->searchTable($searchLocation->name)
        ->assertCanSeeTableRecords([$searchLocation])
        ->assertCanNotSeeTableRecords($locations->skip(1));
});

test('can create location', function () {
    /** @var \Illuminate\Foundation\Testing\TestCase $this */
    $newLocation = Location::factory()->make();

    livewire(ManageLocations::class)
        ->callAction('create', data: [
            'name' => $newLocation->name,
            'description' => $newLocation->description,
            'expiration_notify_days' => $newLocation->expiration_notify_days,
            'parent_id' => null,
        ])
        ->assertNotified();

    $this->assertDatabaseHas('locations', [
        'name' => $newLocation->name,
        'description' => $newLocation->description,
        'expiration_notify_days' => $newLocation->expiration_notify_days,
        'parent_id' => null,
    ]);
});

test('validates location creation data', function (array $data, array $errors): void {
    $newLocation = Location::factory()->make();

    livewire(ManageLocations::class)
        ->callAction('create', data: [
            'name' => $newLocation->name,
            'description' => $newLocation->description,
            'expiration_notify_days' => $newLocation->expiration_notify_days,
            ...$data,
        ])
        ->assertHasActionErrors($errors);
})->with([
    'name is required' => [['name' => null], ['name' => 'required']],
    'name max 255 characters' => [['name' => Str::random(256)], ['name' => 'max']],
    'expiration_notify_days must be integer' => [['expiration_notify_days' => 'invalid'], ['expiration_notify_days' => 'integer']],
    'expiration_notify_days min value 0' => [['expiration_notify_days' => -1], ['expiration_notify_days' => 'min']],
]);

test('can edit location', function (): void {
    $location = Location::factory()->create([
        'name' => 'Original Name',
        'description' => 'Original Description',
        'expiration_notify_days' => 10,
    ]);

    livewire(ManageLocations::class)
        ->callTableAction('edit', $location, data: [
            'name' => 'Updated Name',
            'description' => 'Updated Description',
            'expiration_notify_days' => 20,
            'parent_id' => null,
        ])
        ->assertNotified();

    /** @var \Illuminate\Foundation\Testing\TestCase $this */
    $this->assertDatabaseHas('locations', [
        'id' => $location->id,
        'name' => 'Updated Name',
        'description' => 'Updated Description',
        'expiration_notify_days' => 20,
    ]);
});

test('can delete location', function (): void {
    $location = Location::factory()->create();

    livewire(ManageLocations::class)
        ->callTableAction('delete', $location)
        ->assertNotified();

    expect(Location::find($location->id))->toBeNull();
});

test('can bulk delete locations', function (): void {
    $locations = Location::factory()->count(3)->create();

    livewire(ManageLocations::class)
        ->callTableBulkAction('delete', $locations)
        ->assertNotified();

    foreach ($locations as $location) {
        expect(Location::find($location->id))->toBeNull();
    }
});
