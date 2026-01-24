<?php

declare(strict_types=1);

use App\Http\Requests\StoreBatchRequest;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

$request = null;

beforeEach(function () use (&$request) {
    $request = new StoreBatchRequest;
});

test('authorization always returns true', function () use (&$request) {
    expect($request->authorize())->toBeTrue();
});

test('rules exist for required fields', function () use (&$request) {
    $rules = $request->rules();

    expect($rules)->toHaveKey('item_id')
        ->and($rules)->toHaveKey('expires_at')
        ->and($rules)->toHaveKey('quantity');
});

test('item id validation rules', function () use (&$request) {
    $rules = $request->rules();

    expect($rules['item_id'])->toContain('required')
        ->and($rules['item_id'])->toContain('string')
        ->and($rules['item_id'])->toContain('uuid')
        ->and($rules['item_id'])->toContain('exists:items,id');
});

test('expires at validation rules', function () use (&$request) {
    $rules = $request->rules();

    expect($rules['expires_at'])->toContain('required')
        ->and($rules['expires_at'])->toContain('date')
        ->and($rules['expires_at'])->toContain('after_or_equal:today');
});

test('quantity validation rules', function () use (&$request) {
    $rules = $request->rules();

    expect($rules['quantity'])->toContain('required')
        ->and($rules['quantity'])->toContain('integer')
        ->and($rules['quantity'])->toContain('min:1');
});

test('custom messages are defined', function () use (&$request) {
    $messages = $request->messages();

    expect($messages)->toBeArray()
        ->and($messages)->toHaveKey('item_id.required')
        ->and($messages)->toHaveKey('item_id.exists')
        ->and($messages)->toHaveKey('expires_at.required')
        ->and($messages)->toHaveKey('expires_at.after_or_equal')
        ->and($messages)->toHaveKey('quantity.required')
        ->and($messages)->toHaveKey('quantity.integer')
        ->and($messages)->toHaveKey('quantity.min');
});

test('valid data passes validation', function () use (&$request) {
    $item = Item::factory()->create();
    $location = \App\Models\Location::factory()->create();

    $data = [
        'item_id' => $item->id,
        'location_id' => $location->id,
        'expires_at' => now()->addDays(1)->format('Y-m-d'),
        'quantity' => 10,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeFalse();
});

test('invalid data fails validation', function () use (&$request) {
    $data = [
        'item_id' => 'not-a-uuid',
        'expires_at' => now()->subDays(1)->format('Y-m-d'),
        'quantity' => 0,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('item_id')
        ->and($validator->errors()->toArray())->toHaveKey('expires_at')
        ->and($validator->errors()->toArray())->toHaveKey('quantity');
});
