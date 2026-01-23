<?php

declare(strict_types=1);

use App\Http\Requests\ItemRequest;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

$location = null;

beforeEach(function () use (&$location) {
    $location = Location::factory()->create();
});

test('validation passes with valid data', function () use (&$location) {
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

test('validation passes with emojis', function () use (&$location) {
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

test('validation passes with null description', function () use (&$location) {
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

test('validation passes without tags', function () use (&$location) {
    $request = new ItemRequest;

    $data = [
        'name' => 'Test Item',
        'description' => 'Test Description',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeFalse();
});

test('validation passes with expiration notify days', function () use (&$location) {
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

test('validation passes with expiration notify days zero', function () use (&$location) {
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
