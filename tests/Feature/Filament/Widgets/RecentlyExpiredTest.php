<?php

declare(strict_types=1);

use App\Filament\Widgets\RecentlyExpired;
use App\Models\Batch;
use App\Models\Item;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

test('displays only expired batches sorted by earliest expiration', function (): void {
    $item = Item::factory()->create();
    $location = Location::factory()->create();

    // Create expired batches (expired 1, 5, and 10 days ago)
    $expiredBatch1 = Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'expires_at' => Carbon::now()->subDays(1),
        'quantity' => 10,
    ]);

    $expiredBatch2 = Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'expires_at' => Carbon::now()->subDays(5),
        'quantity' => 20,
    ]);

    $expiredBatch3 = Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'expires_at' => Carbon::now()->subDays(10),
        'quantity' => 30,
    ]);

    // Create non-expired batches (expires in future)
    Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'expires_at' => Carbon::now()->addDays(5),
        'quantity' => 50,
    ]);

    Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'expires_at' => Carbon::now()->addDays(10),
        'quantity' => 60,
    ]);

    $expectedOrder = collect([$expiredBatch3, $expiredBatch2, $expiredBatch1])
        ->sortBy('expires_at')
        ->values();

    livewire(RecentlyExpired::class)
        ->assertSuccessful()
        ->assertCountTableRecords(3)
        ->assertCanSeeTableRecords($expectedOrder, inOrder: true);
});
