<?php

declare(strict_types=1);

use App\Filament\Pages\QuickConsume;
use App\Models\Batch;
use App\Models\Item;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

test('can render quick consume page', function (): void {
    livewire(QuickConsume::class)
        ->assertSuccessful();
});

test('search requires at least 2 characters', function (): void {
    $item = Item::factory()->create(['name' => 'Milk']);

    livewire(QuickConsume::class)
        ->set('search', 'M')
        ->assertSet('search', 'M')
        ->assertSet('selectedItem', null);
});

test('can search items by name', function (): void {
    $item1 = Item::factory()->create(['name' => 'Milk']);
    $item2 = Item::factory()->create(['name' => 'Bread']);
    $item3 = Item::factory()->create(['name' => 'Chocolate Milk']);

    $page = new QuickConsume;
    $page->search = 'Milk';

    $results = $page->getSearchResults();
    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->toArray())->toContain($item1->id, $item3->id)
        ->and($results->pluck('id')->toArray())->not->toContain($item2->id);
});

test('selecting item loads batches in fifo order', function (): void {
    $item = Item::factory()->create(['name' => 'Milk']);
    $location = Location::factory()->create();

    $batch1 = Batch::factory()->for($item)->for($location)->create([
        'expires_at' => Carbon::now()->addDays(10),
        'quantity' => 5,
    ]);
    $batch2 = Batch::factory()->for($item)->for($location)->create([
        'expires_at' => Carbon::now()->addDays(5),
        'quantity' => 3,
    ]);
    $batch3 = Batch::factory()->for($item)->for($location)->create([
        'expires_at' => Carbon::now()->addDays(15),
        'quantity' => 7,
    ]);

    livewire(QuickConsume::class)
        ->call('selectItem', $item->id)
        ->assertSet('selectedItem.id', $item->id)
        ->assertSet('batches', function (?Illuminate\Database\Eloquent\Collection $batches): bool {
            if ($batches === null) {
                return false;
            }

            return $batches->count() === 3
                && $batches->first()->expires_at->isBefore($batches->get(1)->expires_at);
        });
});

test('can consume from batch', function (): void {
    $item = Item::factory()->create(['name' => 'Milk']);
    $location = Location::factory()->create();
    $batch = Batch::factory()->for($item)->for($location)->create([
        'quantity' => 10,
    ]);

    livewire(QuickConsume::class)
        ->call('selectItem', $item->id)
        ->call('consume', $batch->id);

    expect($batch->fresh()->quantity)->toBe(9);
});

test('prevents over consumption', function (): void {
    $item = Item::factory()->create(['name' => 'Milk']);
    $location = Location::factory()->create();
    $batch = Batch::factory()->for($item)->for($location)->create([
        'quantity' => 0,
    ]);

    livewire(QuickConsume::class)
        ->call('selectItem', $item->id)
        ->call('consume', $batch->id);

    expect($batch->fresh()->quantity)->toBe(0);
});

test('auto deletes batch when quantity reaches zero', function (): void {
    $item = Item::factory()->create(['name' => 'Milk']);
    $location = Location::factory()->create();
    $batch = Batch::factory()->for($item)->for($location)->create([
        'quantity' => 1,
    ]);

    livewire(QuickConsume::class)
        ->call('selectItem', $item->id)
        ->call('consume', $batch->id);

    expect(Batch::find($batch->id))->toBeNull();
});

test('updates item quantity after consumption', function (): void {
    $item = Item::factory()->create(['name' => 'Milk']);
    $location = Location::factory()->create();
    $batch = Batch::factory()->for($item)->for($location)->create([
        'quantity' => 10,
    ]);

    $item->update(['quantity' => 10]);

    livewire(QuickConsume::class)
        ->call('selectItem', $item->id)
        ->call('consume', $batch->id);

    expect($item->fresh()->quantity)->toBe(9);
});

test('clears selection when all batches are consumed', function (): void {
    $item = Item::factory()->create(['name' => 'Milk']);
    $location = Location::factory()->create();
    $batch = Batch::factory()->for($item)->for($location)->create([
        'quantity' => 1,
    ]);

    livewire(QuickConsume::class)
        ->call('selectItem', $item->id)
        ->call('consume', $batch->id)
        ->assertSet('selectedItem', null);
});

test('identifies expired batches', function (): void {
    $item = Item::factory()->create(['name' => 'Milk']);
    $location = Location::factory()->create(['expiration_notify_days' => 7]);
    $batch = Batch::factory()->for($item)->for($location)->create([
        'expires_at' => Carbon::now()->subDay(),
        'quantity' => 5,
    ]);

    $page = new QuickConsume;
    $status = $page->getExpirationStatus($batch);

    expect($status['color'])->toBe('danger')
        ->and($status['label'])->toBe('Expired');
});

test('identifies expiring soon batches', function (): void {
    $item = Item::factory()->create(['name' => 'Milk']);
    $location = Location::factory()->create(['expiration_notify_days' => 7]);
    $batch = Batch::factory()->for($item)->for($location)->create([
        'expires_at' => Carbon::now()->addDays(3),
        'quantity' => 5,
    ]);

    $page = new QuickConsume;
    $status = $page->getExpirationStatus($batch);

    expect($status['color'])->toBe('warning')
        ->and($status['label'])->toBe('Expiring Soon');
});

test('identifies good condition batches', function (): void {
    $item = Item::factory()->create(['name' => 'Milk']);
    $location = Location::factory()->create(['expiration_notify_days' => 7]);
    $batch = Batch::factory()->for($item)->for($location)->create([
        'expires_at' => Carbon::now()->addDays(30),
        'quantity' => 5,
    ]);

    $page = new QuickConsume;
    $status = $page->getExpirationStatus($batch);

    expect($status['color'])->toBe('success')
        ->and($status['label'])->toBe('Good');
});

test('can clear selection and return to search', function (): void {
    $item = Item::factory()->create(['name' => 'Milk']);
    $location = Location::factory()->create();
    Batch::factory()->for($item)->for($location)->create(['quantity' => 10]);

    livewire(QuickConsume::class)
        ->call('selectItem', $item->id)
        ->call('clearSelection')
        ->assertSet('selectedItem', null)
        ->assertSet('batches', null)
        ->assertSet('search', '');
});

test('search clears selection when changed', function (): void {
    $item = Item::factory()->create(['name' => 'Milk']);
    $location = Location::factory()->create();
    Batch::factory()->for($item)->for($location)->create(['quantity' => 10]);

    livewire(QuickConsume::class)
        ->call('selectItem', $item->id)
        ->set('search', 'Bread')
        ->assertSet('selectedItem', null)
        ->assertSet('batches', null);
});

test('does not show batches with zero quantity', function (): void {
    $item = Item::factory()->create(['name' => 'Milk']);
    $location = Location::factory()->create();
    Batch::factory()->for($item)->for($location)->create(['quantity' => 0]);
    Batch::factory()->for($item)->for($location)->create(['quantity' => 5]);

    livewire(QuickConsume::class)
        ->call('selectItem', $item->id)
        ->assertSet('batches', function (?Illuminate\Database\Eloquent\Collection $batches): bool {
            if ($batches === null) {
                return false;
            }

            return $batches->count() === 1 && $batches->first()->quantity === 5;
        });
});
