<?php

declare(strict_types=1);

use App\Filament\Widgets\ExpiringItemsWidget;
use App\Models\Batch;
use App\Models\Item;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('renders without error and shows empty state when no items exist', function () {
    livewire(ExpiringItemsWidget::class)
        ->assertSuccessful()
        ->assertCanNotSeeTableRecords([]);
});

it('shows only items with at least one batch (and exp date), sorted by earliest batch expiration, max 10', function () {
    // Freeze time for reliable results
    Carbon::setTestNow('2025-06-01');
    $location = Location::factory()->create();
    $items = Item::factory()->count(12)->create();

    // Give 11 items one batch each; one item gets no batch
    foreach ($items->take(11) as $index => $item) {
        Batch::factory()->for($item)->for($location)->create([
            'expires_at' => Carbon::now()->addDays($index), // item0 soonest, item10 latest
        ]);
    }
    // Also, item[5] gets a second batch that's further in future
    Batch::factory()->for($items[5])->for($location)->create([
        'expires_at' => Carbon::now()->addDays(15),
    ]);
    // The 12th item gets no batches.

    $expected = $items->take(10)
        ->sortBy(fn ($item, $i) => $i) // by assignment above
        ->values();

    livewire(ExpiringItemsWidget::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($expected, inOrder: true);
});

it('does not show items that have only expired batches', function () {
    Carbon::setTestNow('2026-01-01');
    $location = Location::factory()->create();

    // Expired only
    $itemExpired = Item::factory()->create(['name' => 'Yogurt expired']);
    Batch::factory()->for($itemExpired)->for($location)->create([
        'expires_at' => Carbon::now()->subDay(),
    ]);

    // One expired, one future (should show)
    $itemMixed = Item::factory()->create(['name' => 'Cheese mixed']);
    Batch::factory()->for($itemMixed)->for($location)->create([
        'expires_at' => Carbon::now()->subDay(),
    ]);
    Batch::factory()->for($itemMixed)->for($location)->create([
        'expires_at' => Carbon::now()->addDay(),
    ]);

    // All future (should show)
    $itemFuture = Item::factory()->create(['name' => 'Milk fresh']);
    Batch::factory()->for($itemFuture)->for($location)->create([
        'expires_at' => Carbon::now()->addDays(2),
    ]);

    // No batches (should not show)
    $itemNoBatch = Item::factory()->create(['name' => 'Eggs no batch']);

    // Widget should only show Cheese mixed and Milk fresh
    $expected = [$itemMixed, $itemFuture];

    livewire(ExpiringItemsWidget::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($expected)
        ->assertCanNotSeeTableRecords([$itemExpired, $itemNoBatch]);
});

it('shows correct columns: name, location.name, earliest_batch_expiration, quantity', function () {
    $location = Location::factory()->create(['name' => 'TestLoc']);
    $item = Item::factory()->create(['name' => 'Milk', 'quantity' => 10]);
    Batch::factory()->for($item)->for($location)->create(['expires_at' => now()->addDays(2)]);
    livewire(ExpiringItemsWidget::class)
        ->assertCanRenderTableColumn('name')
        ->assertCanRenderTableColumn('location.name')
        ->assertCanRenderTableColumn('earliest_batch_expiration')
        ->assertCanRenderTableColumn('quantity')
        ->assertSee('Milk')
        ->assertSee('TestLoc');
});
