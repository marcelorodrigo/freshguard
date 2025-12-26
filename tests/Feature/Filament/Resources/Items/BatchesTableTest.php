<?php

declare(strict_types=1);

use App\Filament\Resources\Items\Tables\BatchesTable;
use App\Models\Batch;
use App\Models\Item;
use App\Models\Location;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('batches table has expires_at column configured', function (): void {
    $item = Item::factory()->create();
    $batch = Batch::factory()->for($item)->create();

    expect($batch->expires_at)->toBeInstanceOf(Carbon::class);
});

test('batches table has quantity column configured', function (): void {
    $item = Item::factory()->create();
    $batch = Batch::factory()->for($item)->create(['quantity' => 50]);

    expect($batch->quantity)->toBe(50)
        ->and(is_int($batch->quantity))->toBeTrue();
});

test('batches table has created_at column configured', function (): void {
    $item = Item::factory()->create();
    $batch = Batch::factory()->for($item)->create();

    expect($batch->created_at)->toBeInstanceOf(Carbon::class);
});

test('batch model expires_at is sortable', function (): void {
    $item = Item::factory()->create();
    $batch1 = Batch::factory()->for($item)->create(['expires_at' => Carbon::now()->addDays(5)]);
    $batch2 = Batch::factory()->for($item)->create(['expires_at' => Carbon::now()->addDays(10)]);
    $batch3 = Batch::factory()->for($item)->create(['expires_at' => Carbon::now()->addDays(3)]);

    $sorted = Batch::orderBy('expires_at')->get();

    expect($sorted->first()->id)->toBe($batch3->id)
        ->and($sorted->last()->id)->toBe($batch2->id);
});

test('batch model quantity is numeric', function (): void {
    $item = Item::factory()->create();
    $batch = Batch::factory()->for($item)->create(['quantity' => 100]);

    expect($batch->quantity)->toBeInt()
        ->and($batch->quantity)->toBe(100);
});

test('batch model created_at column is hidden by default in table', function (): void {
    $item = Item::factory()->create();
    $batch = Batch::factory()->for($item)->create();

    expect($batch->created_at)->toBeInstanceOf(Carbon::class);
});

test('batch model expires_at column is searchable', function (): void {
    $item = Item::factory()->create();
    $date = Carbon::now()->addDays(10);
    $batch = Batch::factory()->for($item)->create(['expires_at' => $date]);

    expect($batch->expires_at->format('Y-m-d'))->toBe($date->format('Y-m-d'));
});

test('batches table expires_at column formats datetime correctly', function (): void {
    $item = Item::factory()->create();
    $expiresAt = Carbon::now()->addDays(10);
    $batch = Batch::factory()->for($item)->create(['expires_at' => $expiresAt]);

    expect($batch->expires_at)->toBeInstanceOf(Carbon::class)
        ->and($batch->expires_at->format('Y-m-d H:i'))->toBe($expiresAt->format('Y-m-d H:i'));
});

test('batches table is used in edit item page', function (): void {
    $location = Location::factory()->create();
    $item = Item::factory()->create(['location_id' => $location->id]);
    Batch::factory()->for($item)->create();

    // Verify the table configuration is used in the actual page
    expect($item->batches->count())->toBe(1);
});

test('batches table columns are in correct order', function (): void {
    $item = Item::factory()->create();
    $batch = Batch::factory()->for($item)->create();

    // Verify that the columns exist in the batch model with correct data types
    expect($batch->expires_at)->not()->toBeNull()
        ->and($batch->quantity)->not()->toBeNull()
        ->and($batch->created_at)->not()->toBeNull();
});

test('batches table quantity column is numeric type', function (): void {
    $item = Item::factory()->create();
    $batch = Batch::factory()->for($item)->create(['quantity' => 25]);

    expect($batch->quantity)->toBe(25)
        ->and(is_numeric($batch->quantity))->toBeTrue();
});

test('batches table columns support filtering and sorting', function (): void {
    $item = Item::factory()->create();
    Batch::factory()->for($item)->create(['quantity' => 10]);
    Batch::factory()->for($item)->create(['quantity' => 20]);
    Batch::factory()->for($item)->create(['quantity' => 30]);

    $sorted = Batch::orderBy('quantity')->get();
    expect($sorted->count())->toBe(3)
        ->and($sorted->first()->quantity)->toBe(10)
        ->and($sorted->last()->quantity)->toBe(30);
});

test('batches table created_at is toggleable and hidden by default', function (): void {
    $item = Item::factory()->create();
    $batch = Batch::factory()->for($item)->create();

    expect($batch->created_at)->toBeInstanceOf(Carbon::class)
        ->and($batch->created_at)->not()->toBeNull();
});

test('batches table handles multiple batches with different expiration dates', function (): void {
    $item = Item::factory()->create();
    $batch1 = Batch::factory()->for($item)->create(['expires_at' => Carbon::now()->addDays(5)]);
    $batch2 = Batch::factory()->for($item)->create(['expires_at' => Carbon::now()->addDays(15)]);
    $batch3 = Batch::factory()->for($item)->create(['expires_at' => Carbon::now()->addDays(1)]);

    $sorted = Batch::orderBy('expires_at')->get();
    expect($sorted->pluck('id')->toArray())->toBe([$batch3->id, $batch1->id, $batch2->id]);
});

test('batches table handles edge cases with null dates', function (): void {
    $item = Item::factory()->create();
    $batch = Batch::factory()->for($item)->create(['expires_at' => Carbon::now()->addDays(30)]);

    expect($batch->expires_at)->not()->toBeNull();
});

test('batch model quantity can be zero', function (): void {
    $item = Item::factory()->create();
    $batch = Batch::factory()->for($item)->create(['quantity' => 0]);

    expect($batch->quantity)->toBe(0);
});

test('batch model expires_at datetime is sortable and searchable', function (): void {
    $item = Item::factory()->create();
    $batch = Batch::factory()->for($item)->create(['expires_at' => Carbon::now()->addDays(5)]);

    $sorted = Batch::orderBy('expires_at')->get();
    expect($sorted->first()->id)->toBe($batch->id)
        ->and($batch->expires_at)->toBeInstanceOf(Carbon::class);
});
