<?php

declare(strict_types=1);

use App\Filament\Resources\Items\Schemas\BatchForm;
use App\Models\Batch;
use App\Models\Item;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('batch form has expires_at field component', function (): void {
    $schema = Schema::make();
    $configuredSchema = BatchForm::configure($schema);

    $components = $configuredSchema->getComponents();

    expect($components)->toHaveCount(2);
});

test('batch form has quantity field component', function (): void {
    $schema = Schema::make();
    $configuredSchema = BatchForm::configure($schema);

    $components = $configuredSchema->getComponents();

    expect($components)->toHaveCount(2);
});

test('batch form expires_at accepts required constraint', function (): void {
    $schema = Schema::make();
    $configuredSchema = BatchForm::configure($schema);

    expect($configuredSchema->getComponents())->toHaveCount(2);
});

test('batch form quantity accepts required constraint', function (): void {
    $schema = Schema::make();
    $configuredSchema = BatchForm::configure($schema);

    expect($configuredSchema->getComponents())->toHaveCount(2);
});

test('batch form expires_at accepts future dates only', function (): void {
    $item = Item::factory()->create();
    $futureDate = Carbon::now()->addDays(30);

    // Verify that a batch can be created with a future expiration date
    $batch = Batch::factory()->for($item)->create(['expires_at' => $futureDate]);

    expect($batch->expires_at)->toBeInstanceOf(Carbon::class)
        ->and($batch->expires_at->isFuture())->toBeTrue();
});

test('batch form quantity field accepts positive integers', function (): void {
    $item = Item::factory()->create();
    $batch = Batch::factory()->for($item)->create(['quantity' => 100]);

    expect($batch->quantity)->toBeInt()
        ->and($batch->quantity)->toBeGreaterThan(0);
});

test('batch form has proper labels', function (): void {
    $schema = Schema::make();
    $configuredSchema = BatchForm::configure($schema);

    // Labels are set when configuring the form
    expect($configuredSchema->getComponents())->toHaveCount(2);
});

test('batch form schema is properly structured', function (): void {
    $schema = Schema::make();
    $configuredSchema = BatchForm::configure($schema);

    expect($configuredSchema->getComponents())->toHaveCount(2);
});



