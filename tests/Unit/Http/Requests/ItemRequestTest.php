<?php
declare(strict_types=1);

use App\Http\Requests\ItemRequest;
use App\Models\Location;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

$location = null;
$tag = null;

beforeEach(function () use (&$location, &$tag) {
    $location = Location::factory()->create();
    $tag = Tag::factory()->create();
});

test('validation passes with valid data', function () use (&$location, &$tag) {
    $request = new ItemRequest;

    $data = [
        'location_id' => $location->id,
        'name' => 'Test Item',
        'description' => 'Test Description',
        'tags' => [$tag->id],
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation passes with emojis', function () use (&$location, &$tag) {
    $request = new ItemRequest;

    $data = [
        'location_id' => $location->id,
        'name' => '📦',
        'description' => 'Special item 😊',
        'tags' => [$tag->id],
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation passes with null description', function () use (&$location, &$tag) {
    $request = new ItemRequest;

    $data = [
        'location_id' => $location->id,
        'name' => 'Test Item',
        'description' => null,
        'tags' => [$tag->id],
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation passes without tags', function () use (&$location, &$tag) {
    $request = new ItemRequest;

    $data = [
        'location_id' => $location->id,
        'name' => 'Test Item',
        'description' => 'Test Description',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation fails without location id', function () use (&$location, &$tag) {
    $request = new ItemRequest;

    $data = [
        'name' => 'Test Item',
        'description' => 'Test Description',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('location_id');
});

test('validation fails with invalid location id', function () use (&$location, &$tag) {
    $request = new ItemRequest;

    $data = [
        'location_id' => 'not-a-uuid',
        'name' => 'Test Item',
        'description' => 'Test Description',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('location_id');
});

test('validation fails with nonexistent location id', function () use (&$location, &$tag) {
    $request = new ItemRequest;

    $data = [
        'location_id' => Str::uuid()->toString(),
        'name' => 'Test Item',
        'description' => 'Test Description',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('location_id');
});

test('validation fails without name', function () use (&$location, &$tag) {
    $request = new ItemRequest;

    $data = [
        'location_id' => $location->id,
        'description' => 'Test Description',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('name');
});

test('validation fails with name too long', function () use (&$location, &$tag) {
    $request = new ItemRequest;

    $data = [
        'location_id' => $location->id,
        'name' => Str::repeat('a', 256),
        'description' => 'Test Description',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('name');
});

test('validation fails with description too long', function () use (&$location, &$tag) {
    $request = new ItemRequest;

    $data = [
        'location_id' => $location->id,
        'name' => 'Test Item',
        'description' => Str::repeat('a', 256),
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('description');
});

test('validation fails with invalid tags format', function () use (&$location, &$tag) {
    $request = new ItemRequest;

    $data = [
        'location_id' => $location->id,
        'name' => 'Test Item',
        'description' => 'Test Description',
        'tags' => 'not-an-array',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('tags');
});

test('validation fails with invalid tag id', function () use (&$location, &$tag) {
    $request = new ItemRequest;

    $data = [
        'location_id' => $location->id,
        'name' => 'Test Item',
        'description' => 'Test Description',
        'tags' => ['not-a-uuid'],
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('tags.0');
});

test('validation fails with nonexistent tag id', function () use (&$location, &$tag) {
    $request = new ItemRequest;

    $data = [
        'location_id' => $location->id,
        'name' => 'Test Item',
        'description' => 'Test Description',
        'tags' => [Str::uuid()->toString()],
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('tags.0');
});

test('validation passes with expiration notify days', function () use (&$location, &$tag) {
    $request = new ItemRequest;

    $data = [
        'location_id' => $location->id,
        'name' => 'Test Item',
        'description' => 'Test Description',
        'expiration_notify_days' => 5,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation passes with expiration notify days zero', function () use (&$location, &$tag) {
    $request = new ItemRequest;

    $data = [
        'location_id' => $location->id,
        'name' => 'Test Item',
        'description' => 'Test Description',
        'expiration_notify_days' => 0,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation fails with negative expiration notify days', function () use (&$location, &$tag) {
    $request = new ItemRequest;

    $data = [
        'location_id' => $location->id,
        'name' => 'Test Item',
        'description' => 'Test Description',
        'expiration_notify_days' => -1,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('expiration_notify_days');
});

test('validation fails with non integer expiration notify days', function () use (&$location, &$tag) {
    $request = new ItemRequest;

    $data = [
        'location_id' => $location->id,
        'name' => 'Test Item',
        'description' => 'Test Description',
        'expiration_notify_days' => 'not-a-number',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('expiration_notify_days');
});
