<?php

declare(strict_types=1);

use App\Filament\Pages\QuickConsume;
use App\Models\Batch;
use App\Models\Item;
use App\Models\Location;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionMethod;

use function Pest\Livewire\livewire;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

test('page renders with search form', function (): void {
    livewire(QuickConsume::class)
        ->assertFormExists()
        ->assertFormFieldExists('search')
        ->assertSuccessful();
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
        ->set('search', '  Milk  ')
        ->assertSet('searchResults', function (Collection $results) use ($item): bool {
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
        ->set('search', 'milk')
        ->assertSet('searchResults', function (Collection $results) use ($item): bool {
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
        ->assertSet('searchResults', function (Collection $results) use ($item): bool {
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
        ->assertSet('searchResults', function (Collection $results): bool {
            return $results->count() <= 10;
        });
});

test('search results are ordered by item name', function (): void {
    $location = Location::factory()->create();
    $itemB = Item::factory()->create(['name' => 'Test Product B']);
    $itemA = Item::factory()->create(['name' => 'Test Product A']);

    foreach ([$itemB, $itemA] as $item) {
        Batch::factory()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => 5,
            'expires_at' => now()->addDays(30),
        ]);
    }

    livewire(QuickConsume::class)
        ->set('search', 'Test Product')
        ->assertSet('searchResults', function (Collection $results) use ($itemA, $itemB): bool {
            return $results->pluck('id')->all() === [$itemA->id, $itemB->id];
        });
});

test('search treats wildcard characters as literal text', function (): void {
    $location = Location::factory()->create();
    $literalItem = Item::factory()->create(['name' => '100% Juice']);
    $wildcardItem = Item::factory()->create(['name' => '1000 Juice']);

    foreach ([$literalItem, $wildcardItem] as $item) {
        Batch::factory()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => 5,
            'expires_at' => now()->addDays(30),
        ]);
    }

    livewire(QuickConsume::class)
        ->set('search', '100%')
        ->assertSet('searchResults', function (Collection $results) use ($literalItem): bool {
            return $results->pluck('id')->all() === [$literalItem->id];
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
        ->assertSet('searchResults', function (Collection $results) use ($itemWithStock, $itemWithoutStock): bool {
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
        ->assertSet('searchResults', function (Collection $results) use ($expiredBatch, $soonBatch, $futureBatch): bool {
            /** @var Collection<int, Item> $results */
            $batches = $results->firstOrFail()->batches;

            return $batches[0]?->id === $expiredBatch->id
                && $batches[1]?->id === $soonBatch->id
                && $batches[2]?->id === $futureBatch->id;
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
        ->assertSet('searchResults', function (Collection $results) use ($soonBatch, $distantBatch): bool {
            /** @var Collection<int, Item> $results */
            $batches = $results->firstOrFail()->batches;

            return $batches[0]?->id === $soonBatch->id
                && $batches[1]?->id === $distantBatch->id;
        });
});

test('today expiration is not marked as expired', function (): void {
    $component = livewire(QuickConsume::class)->instance();
    $method = new ReflectionMethod($component, 'getExpirationStatus');
    $method->setAccessible(true);

    /** @var array{icon: Heroicon, color: string} $status */
    $status = $method->invoke($component, Carbon::today());

    expect($status['icon'])->toBe(Heroicon::OutlinedClock)
        ->and($status['color'])->toBe('warning');
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
        ->call('consumeBatch', $batch->id);

    $item->refresh();
    expect($item->quantity)->toBe(4);
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
        ->call('consumeBatch', $item->batches->firstOrFail()->id)
        ->assertSet('search', 'Test Item')
        ->assertSet('searchResults', function (Collection $results) use ($item): bool {
            return $results->contains('id', $item->id);
        });
});

test('empty state shown when no search results', function (): void {
    Item::factory()->create(['name' => 'Existing Item']);
    $location = Location::factory()->create();
    Batch::factory()->create([
        'item_id' => Item::firstOrFail()->id,
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

test('consume batch method applies changes', function (): void {
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

test('consume infolist action requires confirmation', function (): void {
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
        ->mountInfolistAction('results.0.batches.0.id', 'consume')
        ->assertInfolistActionMounted('results.0.batches.0.id', 'consume')
        ->unmountInfolistAction();

    $batch->refresh();
    expect($batch->quantity)->toBe(5);
});

test('consume infolist action applies changes after confirmation', function (): void {
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
        ->callInfolistAction('results.0.batches.0.id', 'consume')
        ->assertNotified()
        ->assertSet('searchResults', function (Collection $results): bool {
            /** @var Collection<int, Item> $results */
            return $results->firstOrFail()->batches->firstOrFail()->quantity === 4;
        });

    $batch->refresh();
    expect($batch->quantity)->toBe(4);
});

test('mount triggers search when url search param has at least 2 characters', function (): void {
    $item = Item::factory()->create(['name' => 'Milk Product']);
    $location = Location::factory()->create();
    Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => 5,
        'expires_at' => now()->addDays(30),
    ]);

    livewire(QuickConsume::class, ['search' => 'Milk'])
        ->assertSet('search', 'Milk')
        ->assertSet('searchResults', function (Collection $results) use ($item): bool {
            return $results->contains('id', $item->id);
        });
});

test('consume batch does nothing when batch id is empty', function (): void {
    livewire(QuickConsume::class)
        ->call('consumeBatch', '')
        ->assertNotNotified();
});

test('consume batch reports when batch does not exist', function (): void {
    $nonExistentId = (string) Str::uuid();

    livewire(QuickConsume::class)
        ->call('consumeBatch', $nonExistentId)
        ->assertNotified();
});

test('consume batch refreshes results when batch becomes unavailable', function (): void {
    $item = Item::factory()->create(['name' => 'Test Item']);
    $location = Location::factory()->create();
    $batch = Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => 5,
        'expires_at' => now()->addDays(30),
    ]);

    $component = livewire(QuickConsume::class)
        ->set('search', 'Test Item');

    $batch->delete();

    $component
        ->call('consumeBatch', $batch->id)
        ->assertNotified()
        ->assertSet('searchResults', fn (Collection $results): bool => $results->isEmpty());
});

test('expiry date is formatted as day/month/year', function (): void {
    $item = Item::factory()->create(['name' => 'Test Item']);
    $location = Location::factory()->create();
    $batch = Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => 5,
        'expires_at' => '2026-12-25',
    ]);

    livewire(QuickConsume::class)
        ->set('search', 'Test Item')
        ->assertSet('searchResults', function (Collection $results) use ($batch): bool {
            return $results->contains(
                fn (Item $item): bool => $item->batches->contains(
                    fn (Batch $b): bool => $b->id === $batch->id && $b->expires_at->format('d/m/Y') === '25/12/2026'
                )
            );
        })
        ->assertSuccessful();
});

test('consume batch reports when batch has zero quantity', function (): void {
    $item = Item::factory()->create(['name' => 'Test Item']);
    $location = Location::factory()->create();
    $batch = Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => 0,
        'expires_at' => now()->addDays(30),
    ]);

    livewire(QuickConsume::class)
        ->set('search', 'Test Item')
        ->call('consumeBatch', $batch->id)
        ->assertNotified();

    $batch->refresh();
    expect($batch->quantity)->toBe(0);
});
