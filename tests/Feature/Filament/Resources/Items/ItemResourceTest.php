<?php

declare(strict_types=1);

use App\Filament\Resources\Items\Pages\ManageItems;
use App\Models\Item;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

test('can render page and see table records', function (): void {
    $items = Item::factory()->count(5)->create();

    livewire(ManageItems::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($items)
        ->assertCountTableRecords(5)
        ->assertCanRenderTableColumn('name')
        ->assertCanRenderTableColumn('location.name')
        ->assertCanRenderTableColumn('quantity');
});

test('can search items by name', function (): void {
    $items = Item::factory()->count(5)->create();
    $searchItem = $items->first();

    livewire(ManageItems::class)
        ->searchTable($searchItem->name)
        ->assertCanSeeTableRecords([$searchItem])
        ->assertCanNotSeeTableRecords($items->skip(1));
});

test('can sort items by name', function (): void {
    $items = Item::factory()->count(3)->create();

    livewire(ManageItems::class)
        ->sortTable('name')
        ->assertCanSeeTableRecords($items->sortBy('name'), inOrder: true)
        ->sortTable('name', 'desc')
        ->assertCanSeeTableRecords($items->sortByDesc('name'), inOrder: true);
});

test('can create item with required fields', function (): void {
    $location = Location::factory()->create();
    $newItem = Item::factory()->make();

    livewire(ManageItems::class)
        ->callAction('create', data: [
            'name' => $newItem->name,
            'barcode' => $newItem->barcode,
            'description' => $newItem->description,
            'location_id' => $location->id,
            'expiration_notify_days' => $newItem->expiration_notify_days,
            'tags' => ['Promotion', 'Healthy'],
        ])
        ->assertNotified();

    $this->assertDatabaseHas(Item::class, [
        'name' => $newItem->name,
        'description' => $newItem->description,
        'location_id' => $location->id,
        'expiration_notify_days' => $newItem->expiration_notify_days,
    ]);

    $item = Item::where('name', $newItem->name)->first();
    expect($item->tags)->toBe(['Promotion', 'Healthy']);
});

test('validates item creation data', function (array $data, array $errors): void {
    $location = Location::factory()->create();
    $newItem = Item::factory()->make();

    livewire(ManageItems::class)
        ->callAction('create', data: [
            'name' => $newItem->name,
            'barcode' => $newItem->barcode,
            'description' => $newItem->description,
            'location_id' => $location->id,
            'expiration_notify_days' => $newItem->expiration_notify_days,
            ...$data,
        ])
        ->assertHasActionErrors($errors);
})->with([
    'name is required' => [['name' => null], ['name' => 'required']],
    'name max 255 characters' => [['name' => Str::random(256)], ['name' => 'max']],
    'location_id is required' => [['location_id' => null], ['location_id' => 'required']],
    'expiration_notify_days must be integer' => [['expiration_notify_days' => 'invalid'], ['expiration_notify_days' => 'integer']],
    'expiration_notify_days min value 0' => [['expiration_notify_days' => -1], ['expiration_notify_days' => 'min']],
]);

test('can edit item', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create([
        'location_id' => $location->id,
        'name' => 'Original Item',
        'barcode' => '1234567890123',
        'description' => 'Original Description',
        'expiration_notify_days' => 10,
        'tags' => ['Promotion'],
    ]);

    livewire(ManageItems::class)
        ->callTableAction('edit', $item, data: [
            'name' => 'Updated Item',
            'barcode' => '1234567890123',
            'description' => 'Updated Description',
            'location_id' => $location->id,
            'expiration_notify_days' => 20,
            'tags' => ['Important', 'Healthy'],
        ])
        ->assertNotified();

    $this->assertDatabaseHas(Item::class, [
        'id' => $item->id,
        'name' => 'Updated Item',
        'description' => 'Updated Description',
        'expiration_notify_days' => 20,
    ]);

    $item->refresh();
    expect($item->tags)->toBe(['Important', 'Healthy']);
});

test('can delete item', function (): void {
    $item = Item::factory()->create();

    livewire(ManageItems::class)
        ->callTableAction('delete', $item)
        ->assertNotified();

    expect(Item::find($item->id))->toBeNull();
});

test('can bulk delete items', function (): void {
    $items = Item::factory()->count(3)->create();

    livewire(ManageItems::class)
        ->callTableBulkAction('delete', $items)
        ->assertNotified();

    foreach ($items as $item) {
        expect(Item::find($item->id))->toBeNull();
    }
});
