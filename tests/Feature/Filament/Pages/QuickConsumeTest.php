<?php

declare(strict_types=1);

use App\Filament\Pages\QuickConsume;
use App\Models\Batch;
use App\Models\Item;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

test('page renders with search form', function (): void {
    livewire(QuickConsume::class)
        ->assertSuccessful()
        ->assertFormExists()
        ->assertFormFieldExists('search');
});

test('search requires at least 2 characters', function (): void {
    livewire(QuickConsume::class)
        ->set('search', 'a')
        ->assertSet('searchResults', collect());
});

test('search returns items matching name', function (): void {
    $item = Item::factory()->create(['name' => 'Milk']);
    $location = Location::factory()->create();
    Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => 5,
        'expires_at' => now()->addDays(30),
    ]);

    livewire(QuickConsume::class)
        ->set('search', 'Milk')
        ->assertSet('searchResults', function ($results) use ($item) {
            return $results->contains('id', $item->id);
        });
});

test('search returns items matching description', function (): void {
    $item = Item::factory()->create([
        'name' => 'Product',
        'description' => 'Organic whole milk from grass-fed cows',
    ]);
    $location = Location::factory()->create();
    Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => 5,
        'expires_at' => now()->addDays(30),
    ]);

    livewire(QuickConsume::class)
        ->set('search', 'organic milk')
        ->assertSet('searchResults', function ($results) use ($item) {
            return $results->contains('id', $item->id);
        });
});

test('search returns items matching barcode', function (): void {
    $item = Item::factory()->create([
        'name' => 'Product',
        'barcode' => '1234567890123',
    ]);
    $location = Location::factory()->create();
    Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => 5,
        'expires_at' => now()->addDays(30),
    ]);

    livewire(QuickConsume::class)
        ->set('search', '123456')
        ->assertSet('searchResults', function ($results) use ($item) {
            return $results->contains('id', $item->id);
        });
});

test('search limited to 10 results', function (): void {
    $location = Location::factory()->create();

    Item::factory()->count(15)->create(['name' => 'Test Product'])->each(function ($item) use ($location) {
        Batch::factory()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => 5,
            'expires_at' => now()->addDays(30),
        ]);
    });

    livewire(QuickConsume::class)
        ->set('search', 'Test')
        ->assertSet('searchResults', function ($results) {
            return $results->count() <= 10;
        });
});

test('only shows items with batches having quantity > 0', function (): void {
    $itemWithStock = Item::factory()->create(['name' => 'In Stock']);
    $itemWithoutStock = Item::factory()->create(['name' => 'Out of Stock']);
    $location = Location::factory()->create();

    Batch::factory()->create([
        'item_id' => $itemWithStock->id,
        'location_id' => $location->id,
        'quantity' => 5,
        'expires_at' => now()->addDays(30),
    ]);

    Batch::factory()->create([
        'item_id' => $itemWithoutStock->id,
        'location_id' => $location->id,
        'quantity' => 0,
        'expires_at' => now()->addDays(30),
    ]);

    livewire(QuickConsume::class)
        ->set('search', 'Stock')
        ->assertSet('searchResults', function ($results) use ($itemWithStock, $itemWithoutStock) {
            return $results->contains('id', $itemWithStock->id)
                && ! $results->contains('id', $itemWithoutStock->id);
        });
});

test('batches ordered by expiration with expired first', function (): void {
    $item = Item::factory()->create(['name' => 'Test Item']);
    $location = Location::factory()->create();

    // Create batches with different expiration dates
    $futureBatch = Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => 5,
        'expires_at' => now()->addDays(30),
    ]);

    $expiredBatch = Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => 5,
        'expires_at' => now()->subDays(5),
    ]);

    $soonBatch = Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => 5,
        'expires_at' => now()->addDays(2),
    ]);

    livewire(QuickConsume::class)
        ->set('search', 'Test Item')
        ->assertSet('searchResults', function ($results) use ($expiredBatch, $soonBatch, $futureBatch) {
            $batches = $results->first()->batches;

            return $batches[0]->id === $expiredBatch->id
                && $batches[1]->id === $soonBatch->id
                && $batches[2]->id === $futureBatch->id;
        });
});

test('batches with distant expiration shown after soon-to-expire', function (): void {
    $item = Item::factory()->create(['name' => 'Test Item']);
    $location = Location::factory()->create();

    $distantBatch = Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => 5,
        'expires_at' => now()->addDays(90),
    ]);

    $soonBatch = Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => 5,
        'expires_at' => now()->addDays(3),
    ]);

    livewire(QuickConsume::class)
        ->set('search', 'Test Item')
        ->assertSet('searchResults', function ($results) use ($soonBatch, $distantBatch) {
            $batches = $results->first()->batches;

            return $batches[0]->id === $soonBatch->id
                && $batches[1]->id === $distantBatch->id;
        });
});

test('consume action decrements batch quantity', function (): void {
    $item = Item::factory()->create(['name' => 'Test Item']);
    $location = Location::factory()->create();
    $batch = Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => 5,
        'expires_at' => now()->addDays(30),
    ]);

    livewire(QuickConsume::class)
        ->set('search', 'Test Item')
        ->call('consumeBatch', $batch->id)
        ->assertNotified();

    $batch->refresh();
    expect($batch->quantity)->toBe(4);
});

test('consume action deletes batch when quantity reaches zero', function (): void {
    $item = Item::factory()->create(['name' => 'Test Item']);
    $location = Location::factory()->create();
    $batch = Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => 1,
        'expires_at' => now()->addDays(30),
    ]);

    livewire(QuickConsume::class)
        ->set('search', 'Test Item')
        ->call('consumeBatch', $batch->id)
        ->assertNotified();

    expect(Batch::find($batch->id))->toBeNull();
});

test('parent item quantity updates after consume', function (): void {
    $item = Item::factory()->create(['name' => 'Test Item', 'quantity' => 10]);
    $location = Location::factory()->create();
    $batch = Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => 5,
        'expires_at' => now()->addDays(30),
    ]);

    livewire(QuickConsume::class)
        ->set('search', 'Test Item')
        ->call('consumeBatch', $batch->id);

    $item->refresh();
    expect($item->quantity)->toBe(9);
});

test('search persists after consume', function (): void {
    $item = Item::factory()->create(['name' => 'Test Item']);
    $location = Location::factory()->create();
    Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => 5,
        'expires_at' => now()->addDays(30),
    ]);

    livewire(QuickConsume::class)
        ->set('search', 'Test Item')
        ->call('consumeBatch', $item->batches->first()->id)
        ->assertSet('search', 'Test Item')
        ->assertSet('searchResults', function ($results) use ($item) {
            return $results->contains('id', $item->id);
        });
});

test('empty state shown when no search results', function (): void {
    Item::factory()->create(['name' => 'Existing Item']);
    $location = Location::factory()->create();
    Batch::factory()->create([
        'item_id' => Item::first()->id,
        'location_id' => $location->id,
        'quantity' => 5,
        'expires_at' => now()->addDays(30),
    ]);

    livewire(QuickConsume::class)
        ->set('search', 'NonExistentItem123')
        ->assertSee(__('quick-consume.empty.title'));
});

test('initial empty state shown when search is empty', function (): void {
    livewire(QuickConsume::class)
        ->assertSee(__('quick-consume.empty.initial.title'));
});

test('consume action requires confirmation', function (): void {
    $item = Item::factory()->create(['name' => 'Test Item']);
    $location = Location::factory()->create();
    $batch = Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => 5,
        'expires_at' => now()->addDays(30),
    ]);

    livewire(QuickConsume::class)
        ->set('search', 'Test Item')
        ->assertActionHasConfirmation('consume', ['record' => ['id' => $batch->id]]);
});
