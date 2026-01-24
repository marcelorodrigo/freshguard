<?php

declare(strict_types=1);

use App\Http\Requests\ItemRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('validation passes with valid data', function () {
    $request = new ItemRequest;

    $data = [
        'name' => 'Test Item',
        'description' => 'Test Description',
        'tags' => ['Promotion', 'Healthy'],
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation passes with emojis', function () {
    $request = new ItemRequest;

    $data = [
        'name' => '📦',
        'description' => 'Special item 😊',
        'tags' => ['Promotion'],
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation passes with null description', function () {
    $request = new ItemRequest;

    $data = [
        'name' => 'Test Item',
        'description' => null,
        'tags' => ['Important'],
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation passes without tags', function () {
    $request = new ItemRequest;

    $data = [
        'name' => 'Test Item',
        'description' => 'Test Description',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation fails without name', function () {
    $request = new ItemRequest;

    $data = [
        'description' => 'Test Description',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('name');
});

test('validation fails with name too long', function () {
    $request = new ItemRequest;

    $data = [
        'name' => Str::repeat('a', 256),
        'description' => 'Test Description',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('name');
});

test('validation fails with description too long', function ()  {
    $request = new ItemRequest;

    $data = [
        'name' => 'Test Item',
        'description' => Str::repeat('a', 256),
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('description');
});

test('validation fails with invalid tags format', function () {
    $request = new ItemRequest;

    $data = [
        'name' => 'Test Item',
        'description' => 'Test Description',
        'tags' => 'not-an-array',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('tags');
});

test('validation fails with non-string tag', function () {
    $request = new ItemRequest;

    $data = [
        'name' => 'Test Item',
        'description' => 'Test Description',
        'tags' => [123],
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('tags.0');
});

test('validation fails with tag too long', function () {
    $request = new ItemRequest;

    $data = [
        'name' => 'Test Item',
        'description' => 'Test Description',
        'tags' => [Str::repeat('a', 51)],
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('tags.0');
});

test('validation passes with tag at max length', function () {
    $request = new ItemRequest;

    $data = [
        'name' => 'Test Item',
        'description' => 'Test Description',
        'tags' => [Str::repeat('a', 50)],
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation passes with many tags', function () {
    $request = new ItemRequest;

    $data = [
        'name' => 'Test Item',
        'description' => 'Test Description',
        'tags' => ['Organic', 'Promotion', 'Important', 'Healthy', 'Sale', 'Frozen'],
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation passes with expiration notify days', function () {
    $request = new ItemRequest;

    $data = [
        'name' => 'Test Item',
        'description' => 'Test Description',
        'expiration_notify_days' => 5,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation passes with expiration notify days zero', function () {
    $request = new ItemRequest;

    $data = [
        'name' => 'Test Item',
        'description' => 'Test Description',
        'expiration_notify_days' => 0,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation fails with negative expiration notify days', function () {
    $request = new ItemRequest;

    $data = [
        'name' => 'Test Item',
        'description' => 'Test Description',
        'expiration_notify_days' => -1,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('expiration_notify_days');
});

test('validation fails with non integer expiration notify days', function () {
    $request = new ItemRequest;

    $data = [
        'name' => 'Test Item',
        'description' => 'Test Description',
        'expiration_notify_days' => 'not-a-number',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('expiration_notify_days');
});
