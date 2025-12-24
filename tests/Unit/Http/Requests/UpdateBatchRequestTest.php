<?php
declare(strict_types=1);

use App\Http\Requests\UpdateBatchRequest;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

$request = null;

beforeEach(function () use (&$request) {
    $request = new UpdateBatchRequest;
});

test('authorization always returns true', function () use (&$request) {
    expect($request->authorize())->toBeTrue();
});

test('rules exist for fields', function () use (&$request) {
    $rules = $request->rules();

    expect($rules)->toHaveKey('item_id')
        ->and($rules)->toHaveKey('expires_at')
        ->and($rules)->toHaveKey('quantity');
});

test('item id validation rules', function () use (&$request) {
    $rules = $request->rules();

    expect($rules['item_id'])->toContain('sometimes')
        ->and($rules['item_id'])->toContain('string')
        ->and($rules['item_id'])->toContain('uuid')
        ->and($rules['item_id'])->toContain('exists:items,id');
});

test('expires at validation rules', function () use (&$request) {
    $rules = $request->rules();

    expect($rules['expires_at'])->toContain('sometimes')
        ->and($rules['expires_at'])->toContain('date')
        ->and($rules['expires_at'])->toContain('after_or_equal:today');
});

test('quantity validation rules', function () use (&$request) {
    $rules = $request->rules();

    expect($rules['quantity'])->toContain('sometimes')
        ->and($rules['quantity'])->toContain('integer')
        ->and($rules['quantity'])->toContain('min:0');
});

test('custom messages are defined', function () use (&$request) {
    $messages = $request->messages();

    expect($messages)->toBeArray()
        ->and($messages)->toHaveKey('item_id.exists')
        ->and($messages)->toHaveKey('expires_at.after_or_equal')
        ->and($messages)->toHaveKey('quantity.integer')
        ->and($messages)->toHaveKey('quantity.min');
});

test('valid data passes validation', function () use (&$request) {
    $item = Item::factory()->create();

    // Test with all fields
    $data = [
        'item_id' => $item->id,
        'expires_at' => now()->addDays(1)->format('Y-m-d'),
        'quantity' => 10,
    ];

    $validator = Validator::make($data, $request->rules());
    expect($validator->fails())->toBeFalse();

    // Test with partial data (only updating quantity)
    $partialData = [
        'quantity' => 5,
    ];

    $validator = Validator::make($partialData, $request->rules());
    expect($validator->fails())->toBeFalse();
});

test('invalid data fails validation', function () use (&$request) {
    $data = [
        'item_id' => 'not-a-uuid',
        'expires_at' => now()->subDays(1)->format('Y-m-d'),
        'quantity' => -1,
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->toArray())->toHaveKey('item_id')
        ->and($validator->errors()->toArray())->toHaveKey('expires_at')
        ->and($validator->errors()->toArray())->toHaveKey('quantity');
});
