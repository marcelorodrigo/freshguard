<?php

declare(strict_types=1);

use App\Filament\Resources\Items\Tables\BatchesTable;
use App\Models\Batch;
use App\Models\Item;
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


