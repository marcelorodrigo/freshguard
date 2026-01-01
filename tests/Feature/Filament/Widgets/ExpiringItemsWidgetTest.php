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
    $items = Item::factory()->count(12)->for($location)->create();

    // Give 11 items one batch each; one item gets no batch
    foreach ($items->take(11) as $index => $item) {
        Batch::factory()->for($item)->create([
            'expires_at' => Carbon::now()->addDays($index), // item0 soonest, item10 latest
        ]);
    }
    // Also, item[5] gets a second batch that's further in future
    Batch::factory()->for($items[5])->create([
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

it('shows correct columns: name, location.name, earliest_batch_expiration, quantity', function () {
    $location = Location::factory()->create(['name' => 'TestLoc']);
    $item = Item::factory()->for($location)->create(['name' => 'Milk', 'quantity' => 10]);
    Batch::factory()->for($item)->create(['expires_at' => now()->addDays(2)]);
    livewire(ExpiringItemsWidget::class)
        ->assertCanRenderTableColumn('name')
        ->assertCanRenderTableColumn('location.name')
        ->assertCanRenderTableColumn('earliest_batch_expiration')
        ->assertCanRenderTableColumn('quantity')
        ->assertSee('Milk')
        ->assertSee('TestLoc');
});
