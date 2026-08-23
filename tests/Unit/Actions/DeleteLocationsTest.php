<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\DeleteLocations;
use App\Models\Batch;
use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PDOException;
use ReflectionClass;
use RuntimeException;

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

test('it returns false when a foreign key violation occurs during delete', function (): void {
    $location = Location::factory()->create();

    Location::flushEventListeners();
    Location::deleting(function (Location $model): void {
        $previous = new PDOException('FK violation');
        $previous->errorInfo = ['23000', 1451, 'Cannot delete or update a parent row'];

        $exception = new QueryException('mysql', 'DELETE FROM locations WHERE id = ?', [], $previous);

        $ref = new ReflectionClass($exception);
        $prop = $ref->getProperty('code');
        $prop->setValue($exception, '23000');

        throw $exception;
    });

    expect((new DeleteLocations)(new Collection([$location])))->toBeFalse();
    expect(Location::find($location->id))->not->toBeNull();
});

test('it rethrows a non foreign key query exception during delete', function (): void {
    $location = Location::factory()->create();

    Location::flushEventListeners();
    Location::deleting(function (Location $model): void {
        $previous = new PDOException('Deadlock');
        $previous->errorInfo = ['40001', 1213, 'Deadlock found'];

        $exception = new QueryException('mysql', 'DELETE FROM locations WHERE id = ?', [], $previous);

        $ref = new ReflectionClass($exception);
        $prop = $ref->getProperty('code');
        $prop->setValue($exception, '40001');

        throw $exception;
    });

    (new DeleteLocations)(new Collection([$location]));
})->throws(QueryException::class);

test('it rethrows a generic throwable during delete', function (): void {
    $location = Location::factory()->create();

    Location::flushEventListeners();
    Location::deleting(function (Location $model): void {
        throw new RuntimeException('Something went wrong');
    });

    (new DeleteLocations)(new Collection([$location]));
})->throws(RuntimeException::class);
