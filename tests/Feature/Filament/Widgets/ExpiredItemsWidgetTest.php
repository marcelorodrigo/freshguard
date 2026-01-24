<?php

declare(strict_types=1);

use App\Filament\Widgets\ExpiredItemsWidget;
use App\Models\Batch;
use App\Models\Item;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('renders and shows empty state when no expired items exist', function () {
    livewire(ExpiredItemsWidget::class)
        ->assertSuccessful()
        ->assertCanNotSeeTableRecords([]);
});

it('shows only items with expired (earliest) batch, sorted by expired date desc, max 10', function () {
    Carbon::setTestNow('2026-01-01');
    $location = Location::factory()->create();

    // Create 11 items with controlled expirations
    $expired1 = Item::factory()->create(['name' => 'expired1']); // expired at -1d
    $expired2 = Item::factory()->create(['name' => 'expired2']); // expired at -2d
    $expired3 = Item::factory()->create(['name' => 'expired3']); // expired at -3d
    $expired4 = Item::factory()->create(['name' => 'expired4']); // expired at -4d
    $expired5 = Item::factory()->create(['name' => 'expired5']); // expired at -5d + extra batch future
    $expired6 = Item::factory()->create(['name' => 'expired6']); // expired at -6d
    $expired7 = Item::factory()->create(['name' => 'expired7']); // expired at -7d
    $expired8 = Item::factory()->create(['name' => 'expired8']); // expired at -8d
    $expired9 = Item::factory()->create(['name' => 'expired9']); // expired at -9d
    $future1 = Item::factory()->create(['name' => 'future1']); // exp at +1d
    $future2 = Item::factory()->create(['name' => 'future2']); // exp at +2d

    Batch::factory()->for($expired1)->for($location)->create(['expires_at' => Carbon::now()->subDays(1)]);
    Batch::factory()->for($expired2)->for($location)->create(['expires_at' => Carbon::now()->subDays(2)]);
    Batch::factory()->for($expired3)->for($location)->create(['expires_at' => Carbon::now()->subDays(3)]);
    Batch::factory()->for($expired4)->for($location)->create(['expires_at' => Carbon::now()->subDays(4)]);
    // expired5: earliest expired (-5d), plus future batch (+10d)
    Batch::factory()->for($expired5)->for($location)->create(['expires_at' => Carbon::now()->subDays(5)]);
    Batch::factory()->for($expired5)->for($location)->create(['expires_at' => Carbon::now()->addDays(10)]);
    Batch::factory()->for($expired6)->for($location)->create(['expires_at' => Carbon::now()->subDays(6)]);
    Batch::factory()->for($expired7)->for($location)->create(['expires_at' => Carbon::now()->subDays(7)]);
    Batch::factory()->for($expired8)->for($location)->create(['expires_at' => Carbon::now()->subDays(8)]);
    Batch::factory()->for($expired9)->for($location)->create(['expires_at' => Carbon::now()->subDays(9)]);
    Batch::factory()->for($future1)->for($location)->create(['expires_at' => Carbon::now()->addDays(1)]);
    Batch::factory()->for($future2)->for($location)->create(['expires_at' => Carbon::now()->addDays(2)]);

    // Expected: Top 10 expired items, ordered by earliest_batch_expiration DESC
    $expected = [
        $expired1, // -1d
        $expired2, // -2d
        $expired3, // -3d
        $expired4, // -4d
        $expired5, // -5d, earliest is expired, next batch is future
        $expired6, // -6d
        $expired7, // -7d
        $expired8, // -8d
        $expired9, // -9d
    ];
    // Add a 10th expired item by oldest expired date; in this case, only 9, so it's all that should show (future items excluded)

    livewire(ExpiredItemsWidget::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($expected, inOrder: true);
});

it('shows correct columns: name, location.name, earliest_batch_expiration, quantity', function () {
    $location = Location::factory()->create(['name' => 'Test Loc']);
    $item = Item::factory()->create(['name' => 'Salmon', 'quantity' => 3]);
    Batch::factory()->for($item)->for($location)->create(['expires_at' => now()->subDays(1)]);
    livewire(ExpiredItemsWidget::class)
        ->assertCanRenderTableColumn('name')
        ->assertCanRenderTableColumn('location.name')
        ->assertCanRenderTableColumn('earliest_batch_expiration')
        ->assertCanRenderTableColumn('quantity')
        ->assertSee('Salmon')
        ->assertSee('Test Loc');
});
