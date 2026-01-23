<?php

namespace Tests\Unit\Models;

use App\Models\Batch;
use App\Models\Item;
use App\Models\Location;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('it uses uuids as primary key', function () {
    $batch = Batch::factory()->create();
    expect($batch->id)->toBeString()
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

test('it belongs to an item', function () {
    $batch = Batch::factory()->create();
    expect($batch->item())->toBeInstanceOf(BelongsTo::class)
        ->and($batch->item)->toBeInstanceOf(Item::class);
});

test('it belongs to a location', function () {
    $batch = Batch::factory()->create();
    expect($batch->location())->toBeInstanceOf(BelongsTo::class)
        ->and($batch->location)->toBeInstanceOf(Location::class);
});

test('it has correct fillable attributes', function () {
    $expected = [
        'item_id',
        'location_id',
        'expires_at',
        'quantity',
    ];
    expect((new Batch)->getFillable())->toBe($expected);
});

test('it casts attributes correctly', function () {
    $batch = Batch::factory()->create();
    expect($batch->item_id)->toBeString()
        ->and($batch->location_id)->toBeString()
        ->and($batch->expires_at)->toBeInstanceOf(Carbon::class)
        ->and($batch->quantity)->toBeInt();
});

test('it creates valid factory instances', function () {
    $batch = Batch::factory()->create();
    expect(Batch::where('id', $batch->id)->exists())->toBeTrue();
    expect($batch->item_id)->not->toBeNull()
        ->and($batch->location_id)->not->toBeNull()
        ->and($batch->expires_at)->not->toBeNull()
        ->and($batch->quantity)->not->toBeNull();
});

test('cannot create duplicate batch for same (item, location, expires_at)', function () {
    $item = Item::factory()->create();
    $location = Location::factory()->create();
    $expiresAt = now()->addDays(5);
    Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'expires_at' => $expiresAt,
    ]);
    expect(fn () => Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'expires_at' => $expiresAt,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('batch requires both item_id and location_id', function () {
    $location = Location::factory()->create();
    $expiresAt = now()->addDays(6);
    expect(fn () => Batch::factory()->create([
        'item_id' => null,
        'location_id' => $location->id,
        'expires_at' => $expiresAt,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
    $item = Item::factory()->create();
    expect(fn () => Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => null,
        'expires_at' => $expiresAt,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('an item can exist in multiple locations through batches', function () {
    $item = Item::factory()->create();
    $locationA = Location::factory()->create();
    $locationB = Location::factory()->create();
    $expA = now()->addDays(2);
    $expB = now()->addDays(5);
    Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $locationA->id,
        'expires_at' => $expA,
        'quantity' => 5,
    ]);
    Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $locationB->id,
        'expires_at' => $expB,
        'quantity' => 10,
    ]);
    $batches = $item->batches;
    expect($batches)->toHaveCount(2)
        ->and($batches->pluck('location_id'))->toContain($locationA->id)
        ->and($batches->pluck('location_id'))->toContain($locationB->id);
});

test('item quantity is sum of all batches across all locations', function () {
    $item = Item::factory()->create();
    $locationA = Location::factory()->create();
    $locationB = Location::factory()->create();
    $locationC = Location::factory()->create();
    $expA = now()->addDays(2);
    $expB = now()->addDays(5);
    $expC = now()->addDays(10);
    Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $locationA->id,
        'expires_at' => $expA,
        'quantity' => 7,
    ]);
    Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $locationB->id,
        'expires_at' => $expB,
        'quantity' => 12,
    ]);
    Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $locationC->id,
        'expires_at' => $expC,
        'quantity' => 4,
    ]);
    $item->refresh();
    expect($item->quantity)->toBe(23);
});

test('migration merges duplicate batches by summing quantities', function () {
    $item = Item::factory()->create();
    $location = Location::factory()->create();
    $expiresAt = now()->addDays(15)->startOfDay();

    // Insert duplicates directly bypassing model (simulate pre-migration state)
    \Illuminate\Support\Facades\\DB::table('batches')->insert([
        [
            'id' => uuid_create(),
            'item_id' => $item->id,
            'location_id' => $location->id,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'quantity' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => uuid_create(),
            'item_id' => $item->id,
            'location_id' => $location->id,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'quantity' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // Run the migration logic
    require base_path('database/migrations/2026_01_23_142146_migrate_batch_location_and_merge_duplicates.php');
    (new (require base_path('database/migrations/2026_01_23_142146_migrate_batch_location_and_merge_duplicates.php')))->up();

    $finalBatches = \\DB::table('batches')->where([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
    ])->get();
    expect($finalBatches)->toHaveCount(1);
    expect($finalBatches->first()->quantity)->toBe(12);
});

// REST OF ORIGINAL TEST CASES...

test('it can create batches with custom attributes', function () {
    $item = Item::factory()->create();
    $location = Location::factory()->create();
    $expiresAt = now()->addDays(30);
    $batch = Batch::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'expires_at' => $expiresAt,
        'quantity' => 42,
    ]);
    expect($batch->item_id)->toBe($item->id)
        ->and($batch->location_id)->toBe($location->id)
        ->and($batch->expires_at->toDateTimeString())->toBe($expiresAt->toDateTimeString())
        ->and($batch->quantity)->toBe(42);
    $item->refresh();
    expect($item->quantity)->toBe(42);
});

// ...continue with all the original test logic, updating any reference
// to require location_id and match the new model structure.
