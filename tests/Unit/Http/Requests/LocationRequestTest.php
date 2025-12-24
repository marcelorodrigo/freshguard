<?php

use App\Http\Requests\LocationRequest;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('validation passes with valid data', function () {
    $request = new LocationRequest();

    $data = [
        'name' => 'Test Location',
        'description' => 'Test Description',
        'parent_id' => null,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation passes with emojis', function () {
    $request = new LocationRequest();

    $data = [
        'name' => '🏠',
        'description' => 'Nice place to relax 😊',
        'parent_id' => null,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation passes with null description', function () {
    $request = new LocationRequest();

    $data = [
        'name' => 'Test Location',
        'description' => null,
        'parent_id' => null,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation passes with valid parent id', function () {
    // Create a parent location
    $parent = Location::create([
        'name' => 'Parent Location',
        'description' => 'Parent Description',
    ]);

    $request = new LocationRequest();

    $data = [
        'name' => 'Child Location',
        'description' => 'Child Description',
        'parent_id' => $parent->id,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation fails without name', function () {
    $request = new LocationRequest();

    $data = [
        'description' => 'Test Description',
        'parent_id' => null,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('name');
});

test('validation fails with name too long', function () {
    $request = new LocationRequest();

    $data = [
        'name' => Str::repeat('a', 256),
        'description' => 'Test Description',
        'parent_id' => null,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('name');
});

test('validation fails with description too long', function () {
    $request = new LocationRequest();

    $data = [
        'name' => 'Test Location',
        'description' => Str::repeat('a', 256),
        'parent_id' => null,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('description');
});

test('validation fails with invalid parent id', function () {
    $request = new LocationRequest();

    $data = [
        'name' => 'Test Location',
        'description' => 'Test Description',
        'parent_id' => Str::uuid(),
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('parent_id');
});

test('validation passes with expiration notify days', function () {
    $request = new LocationRequest();

    $data = [
        'name' => 'Test Location',
        'description' => 'Test Description',
        'parent_id' => null,
        'expiration_notify_days' => 5,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation passes with expiration notify days zero', function () {
    $request = new LocationRequest();

    $data = [
        'name' => 'Test Location',
        'description' => 'Test Description',
        'parent_id' => null,
        'expiration_notify_days' => 0,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation fails with negative expiration notify days', function () {
    $request = new LocationRequest();

    $data = [
        'name' => 'Test Location',
        'description' => 'Test Description',
        'parent_id' => null,
        'expiration_notify_days' => -1,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('expiration_notify_days');
});

test('validation fails with non integer expiration notify days', function () {
    $request = new LocationRequest();

    $data = [
        'name' => 'Test Location',
        'description' => 'Test Description',
        'parent_id' => null,
        'expiration_notify_days' => 'not-a-number',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue();
});
