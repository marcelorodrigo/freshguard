<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('it can create a location with factory', function () {
    $location = Location::factory()->create();
    expect($location)->toBeInstanceOf(Location::class)
        ->and($location->id)->not->toBeNull()
        ->and($location->id)->toBeString();
});

test('it has uuid as primary key', function () {
    $location = Location::factory()->create();
    expect($location->id)->toBeString()
        ->and(Str::isUuid($location->id))->toBeTrue();
});

test('it can have a parent', function () {
    $parent = Location::factory()->create();
    $child = Location::factory()->create(['parent_id' => $parent->id]);
    expect($child->parent->is($parent))->toBeTrue();
});

test('it can have children', function () {
    $parent = Location::factory()->create();
    $child1 = Location::factory()->create(['parent_id' => $parent->id]);
    $child2 = Location::factory()->create(['parent_id' => $parent->id]);
    expect($parent->children)->toHaveCount(2)
        ->and($parent->children->contains($child1))->toBeTrue()
        ->and($parent->children->contains($child2))->toBeTrue();
});

test('it can have no parent', function () {
    $location = Location::factory()->create(['parent_id' => null]);
    expect($location->parent)->toBeNull();
});

test('it can have no children', function () {
    $location = Location::factory()->create();
    expect($location->children)->toHaveCount(0);
});

test('fillable attributes', function () {
    $data = [
        'name' => 'Test Location',
        'description' => 'Test Description',
        'expiration_notify_days' => 10,
        'parent_id' => null,
    ];
    $location = Location::create($data);
    expect($location->name)->toBe('Test Location')
        ->and($location->description)->toBe('Test Description')
        ->and($location->expiration_notify_days)->toBe(10)
        ->and($location->parent_id)->toBeNull();
});
