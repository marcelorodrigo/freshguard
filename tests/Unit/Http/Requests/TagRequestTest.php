<?php

use App\Http\Requests\TagRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

test('validation passes with valid data', function () {
    $request = new TagRequest();

    $data = [
        'name' => 'Test Tag',
        'description' => 'Test Description',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation passes with emojis', function () {
    $request = new TagRequest();

    $data = [
        'name' => '🏷️',
        'description' => 'Special tag 😊',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation passes with null description', function () {
    $request = new TagRequest();

    $data = [
        'name' => 'Test Tag',
        'description' => null,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation fails without name', function () {
    $request = new TagRequest();

    $data = [
        'description' => 'Test Description',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('name');
});

test('validation fails with name too long', function () {
    $request = new TagRequest();

    $data = [
        'name' => Str::repeat('a', 256),
        'description' => 'Test Description',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('name');
});
