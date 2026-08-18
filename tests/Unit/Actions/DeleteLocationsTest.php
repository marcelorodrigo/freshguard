<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\DeleteLocations;
use App\Models\Batch;
use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('it treats an empty collection as a successful no-op', function (): void {
    expect((new DeleteLocations)(new Collection))->toBeTrue();
});

test('it deletes empty locations', function (): void {
    $locations = Location::factory()->count(2)->create();

    expect((new DeleteLocations)($locations))->toBeTrue();
    expect(Location::query()->whereKey($locations->modelKeys())->exists())->toBeFalse();
});

test('it rejects a populated location without deleting it', function (): void {
    $location = Location::factory()->create();
    Batch::factory()->for($location)->create();

    expect((new DeleteLocations)(new Collection([$location])))->toBeFalse();
    expect(Location::find($location->id))->not->toBeNull();
});

test('it rejects mixed selections atomically', function (): void {
    $emptyLocation = Location::factory()->create();
    $populatedLocation = Location::factory()->create();
    Batch::factory()->for($populatedLocation)->create();

    expect((new DeleteLocations)(new Collection([$emptyLocation, $populatedLocation])))->toBeFalse();
    expect(Location::find($emptyLocation->id))->not->toBeNull()
        ->and(Location::find($populatedLocation->id))->not->toBeNull();
});

test('it keeps children as root locations when deleting an empty parent', function (): void {
    $parent = Location::factory()->create();
    $child = Location::factory()->create(['parent_id' => $parent->id]);

    expect((new DeleteLocations)(new Collection([$parent])))->toBeTrue();
    expect(Location::find($parent->id))->toBeNull()
        ->and($child->fresh()?->parent_id)->toBeNull();
});
