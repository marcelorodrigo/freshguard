<?php

declare(strict_types=1);

use App\Actions\SearchItems;
use App\Models\Batch;
use App\Models\Item;
use App\Models\Location;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

/**
 * @param  array<string, mixed>  $itemAttributes
 * @param  array<string, mixed>  $batchAttributes
 */
function createSearchableItem(Location $location, array $itemAttributes = [], array $batchAttributes = []): Item
{
    $item = Item::factory()->create($itemAttributes);

    Batch::factory()->create(array_merge([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => 5,
        'expires_at' => now()->addDays(30),
    ], $batchAttributes));

    return $item;
}

test('it returns an empty collection for terms shorter than two characters', function (): void {
    $location = Location::factory()->create();
    createSearchableItem($location, ['name' => 'Milk']);

    expect((new SearchItems)('m'))->toBeEmpty()
        ->and((new SearchItems)('  '))->toBeEmpty();
});

test('it trims the search term', function (): void {
    $location = Location::factory()->create();
    $item = createSearchableItem($location, ['name' => 'Milk']);

    expect((new SearchItems)('  Milk  ')->pluck('id')->all())->toBe([$item->id]);
});

test('it ranks exact barcode matches before prefix and name matches', function (): void {
    $location = Location::factory()->create();

    $namePrefix = createSearchableItem($location, [
        'name' => 'ABC123 Product',
        'barcode' => '9999999999999',
    ]);
    $barcodePrefix = createSearchableItem($location, [
        'name' => 'Alpha Product',
        'barcode' => 'ABC1234567890',
    ]);
    $exactBarcode = createSearchableItem($location, [
        'name' => 'Zeta Product',
        'barcode' => 'ABC123',
    ]);

    expect((new SearchItems)('ABC123')->pluck('id')->all())
        ->toBe([$exactBarcode->id, $barcodePrefix->id, $namePrefix->id]);
});

test('it ranks name prefix matches before description substring matches', function (): void {
    $location = Location::factory()->create();

    $descriptionMatch = createSearchableItem($location, [
        'name' => 'Something Else',
        'description' => 'Contains Milk',
    ]);
    $nameMatch = createSearchableItem($location, [
        'name' => 'Milk Chocolate',
        'description' => null,
    ]);

    expect((new SearchItems)('Milk')->pluck('id')->all())
        ->toBe([$nameMatch->id, $descriptionMatch->id]);
});

test('it returns a deterministic page when more than ten items match', function (): void {
    $location = Location::factory()->create();

    foreach (range(1, 15) as $index) {
        createSearchableItem($location, ['name' => sprintf('Test Product %02d', $index)]);
    }

    $firstResults = (new SearchItems)('Test Product')->pluck('id')->all();
    $secondResults = (new SearchItems)('Test Product')->pluck('id')->all();

    expect($firstResults)->toHaveCount(10)
        ->and($secondResults)->toBe($firstResults);
});

test('it returns each item only once when it matches several tiers', function (): void {
    $location = Location::factory()->create();

    $item = createSearchableItem($location, [
        'name' => 'Milk Chocolate',
        'barcode' => 'MILK123',
    ]);

    expect((new SearchItems)('Milk')->pluck('id')->all())->toBe([$item->id]);
});

test('it treats underscore and percent as literal characters', function (): void {
    $location = Location::factory()->create();

    $literalUnderscore = createSearchableItem($location, ['name' => 'Item_One']);
    createSearchableItem($location, ['name' => 'ItemXOne']);

    $literalPercent = createSearchableItem($location, ['name' => '100% Juice']);
    createSearchableItem($location, ['name' => '1000 Juice']);

    expect((new SearchItems)('Item_')->pluck('id')->all())->toBe([$literalUnderscore->id])
        ->and((new SearchItems)('100%')->pluck('id')->all())->toBe([$literalPercent->id]);
});

test('it excludes items without positive stock', function (): void {
    $location = Location::factory()->create();

    $stocked = createSearchableItem($location, ['name' => 'Milk']);
    createSearchableItem($location, ['name' => 'Milk Powder'], batchAttributes: ['quantity' => 0]);

    expect((new SearchItems)('Milk')->pluck('id')->all())->toBe([$stocked->id]);
});

test('it skips the substring fallback when the prefix tiers fill the limit', function (): void {
    $location = Location::factory()->create();

    foreach (range(1, 10) as $index) {
        createSearchableItem($location, ['name' => sprintf('Alpha Product %02d', $index)]);
    }

    createSearchableItem($location, ['name' => 'XAlpha Product']);

    $component = new SearchItems;

    DB::flushQueryLog();
    DB::enableQueryLog();

    $results = $component('Alpha');

    $queries = DB::getQueryLog();

    expect($results)->toHaveCount(10);
    expect(collect($queries)->contains(
        fn (array $query): bool => collect($query['bindings'])->contains(
            fn (mixed $binding): bool => is_string($binding) && str_starts_with($binding, '%')
        )
    ))->toBeFalse();
});

test('it keeps related loading bounded regardless of the number of matches', function (): void {
    $location = Location::factory()->create();

    createSearchableItem($location, ['name' => 'Single Milk']);

    DB::flushQueryLog();
    DB::enableQueryLog();
    (new SearchItems)('Single Milk');
    $singleQueries = DB::getQueryLog();

    foreach (range(1, 15) as $index) {
        createSearchableItem($location, ['name' => sprintf('Milk %02d', $index)]);
    }

    DB::flushQueryLog();
    (new SearchItems)('Milk');
    $manyQueries = DB::getQueryLog();

    $batchQueries = collect($manyQueries)->filter(
        fn (array $query): bool => str_contains($query['query'], 'from `batches`')
            && str_contains($query['query'], '`item_id` in')
    );

    expect(count($singleQueries))->toBeLessThanOrEqual(8)
        ->and(count($manyQueries))->toBeLessThanOrEqual(8)
        ->and($batchQueries)->toHaveCount(1);
});

test('it does not eager load the parent item on batches', function (): void {
    $location = Location::factory()->create();
    createSearchableItem($location, ['name' => 'Milk']);

    $item = (new SearchItems)('Milk')->firstOrFail();

    expect($item->batches->firstOrFail()->relationLoaded('item'))->toBeFalse()
        ->and($item->batches->firstOrFail()->relationLoaded('location'))->toBeTrue();
});
