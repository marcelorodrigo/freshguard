<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Batch;
use App\Models\Item;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @param  array<int, mixed>  $bindings
 * @return array<int, \stdClass>
 */
function explainQuery(string $sql, array $bindings): array
{
    /** @var array<int, \stdClass> $result */
    $result = DB::select($sql, $bindings);

    return $result;
}

uses(LazilyRefreshDatabase::class);

$expirationIndexes = [
    'batches_item_id_expires_at_index',
    'batches_expires_at_index',
];

test('migration adds the expiration indexes', function () use ($expirationIndexes): void {
    $existing = collect(DB::select('SHOW INDEXES FROM batches'))
        ->pluck('Key_name')
        ->unique();

    foreach ($expirationIndexes as $index) {
        expect($existing)->toContain($index);
    }
});

test('expiration queries use the intended indexes', function (): void {
    $item = Item::factory()->create();

    // Many batches for the focused item so the planner favors the composite index
    Batch::factory()->count(120)->create([
        'item_id' => $item->id,
        'expires_at' => fn (): Carbon => Carbon::now()->addDays(fake()->numberBetween(-20, 20)),
    ]);

    Item::factory()->count(3)->create()->each(function (Item $other): void {
        Batch::factory()->count(10)->create([
            'item_id' => $other->id,
            'expires_at' => fn (): Carbon => Carbon::now()->addDays(fake()->numberBetween(-20, 20)),
        ]);
    });

    $perItemPlan = explainQuery(
        'EXPLAIN SELECT expires_at FROM batches WHERE item_id = ? ORDER BY expires_at ASC LIMIT 1',
        [$item->id],
    );

    expect($perItemPlan[0]->possible_keys)->toContain('batches_item_id_expires_at_index');

    $expiryPlan = explainQuery(
        'EXPLAIN SELECT * FROM batches WHERE expires_at < ? ORDER BY expires_at ASC LIMIT 5',
        [Carbon::now()->toDateString()],
    );

    expect($expiryPlan[0]->key)->toBe('batches_expires_at_index');
});

test('expired batches query returns correct results and order', function (): void {
    $item = Item::factory()->create();

    $dates = [
        Carbon::now()->subDays(10),
        Carbon::now()->subDays(1),
        Carbon::now()->subDays(5),
        Carbon::now()->addDays(5),
        Carbon::now()->addDays(10),
    ];

    foreach ($dates as $date) {
        Batch::factory()->create([
            'item_id' => $item->id,
            'expires_at' => $date,
            'quantity' => 10,
        ]);
    }

    $results = Batch::query()
        ->where('expires_at', '<', today())
        ->orderBy('expires_at', 'asc')
        ->get();

    /** @var Batch $first */
    $first = $results->first();
    /** @var Batch $last */
    $last = $results->last();

    expect($results)->toHaveCount(3)
        ->and($first->expires_at->format('Y-m-d'))
        ->toBe(Carbon::now()->subDays(10)->format('Y-m-d'))
        ->and($last->expires_at->format('Y-m-d'))
        ->toBe(Carbon::now()->subDays(1)->format('Y-m-d'));
});

test('expiration indexes are reversible', function () use ($expirationIndexes): void {
    // Mirror the migration's down() step. The (item_id, expires_at) index backs the
    // item_id foreign key, so the FK must be dropped before the index can be removed.
    Schema::table('batches', function (Blueprint $table): void {
        $table->dropIndex('batches_expires_at_index');
        $table->dropForeign(['item_id']);
        $table->dropIndex('batches_item_id_expires_at_index');
        $table->foreign('item_id')
            ->references('id')
            ->on('items')
            ->onDelete('cascade');
    });

    $afterDrop = collect(DB::select('SHOW INDEXES FROM batches'))
        ->pluck('Key_name')
        ->unique();

    foreach ($expirationIndexes as $index) {
        expect($afterDrop)->not->toContain($index);
    }

    // Mirror the migration's up() step to restore state for the rest of the suite
    Schema::table('batches', function (Blueprint $table): void {
        $table->index(['item_id', 'expires_at'], 'batches_item_id_expires_at_index');
        $table->index('expires_at', 'batches_expires_at_index');
    });

    $afterReAdd = collect(DB::select('SHOW INDEXES FROM batches'))
        ->pluck('Key_name')
        ->unique();

    foreach ($expirationIndexes as $index) {
        expect($afterReAdd)->toContain($index);
    }
});
