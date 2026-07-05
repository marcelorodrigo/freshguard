<?php

declare(strict_types=1);

use App\Filament\Resources\Items\Pages\CreateItem;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenFoodFacts\Laravel\Facades\OpenFoodFacts;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

test('barcode lookup populates empty fields from product data', function (): void {
    OpenFoodFacts::shouldReceive('barcode')
        ->with('3017620422003')
        ->once()
        ->andReturn([
            'product_name' => 'Nutella',
            'generic_name' => 'Hazelnut spread',
            'categories_hierarchy' => ['en:spreads', 'en:hazelnut-spreads'],
        ]);

    livewire(CreateItem::class)
        ->fillForm([
            'name' => null,
            'description' => null,
            'tags' => [],
        ])
        ->set('data.barcode', '3017620422003')
        ->assertSchemaStateSet([
            'name' => 'Nutella',
            'description' => 'Hazelnut spread',
            'tags' => ['spreads', 'hazelnut-spreads'],
        ])
        ->assertNotified();
});

test('barcode lookup does not override existing field values', function (): void {
    OpenFoodFacts::shouldReceive('barcode')
        ->with('3017620422003')
        ->once()
        ->andReturn([
            'product_name' => 'Nutella',
            'generic_name' => 'Hazelnut spread',
            'categories_hierarchy' => ['en:spreads', 'en:hazelnut-spreads'],
        ]);

    livewire(CreateItem::class)
        ->fillForm([
            'name' => 'My Custom Name',
            'description' => null,
            'tags' => [],
        ])
        ->set('data.barcode', '3017620422003')
        ->assertSchemaStateSet([
            'name' => 'My Custom Name',
            'description' => 'Hazelnut spread',
            'tags' => ['spreads', 'hazelnut-spreads'],
        ])
        ->assertNotified();
});

test('barcode lookup shows warning when product not found', function (): void {
    OpenFoodFacts::shouldReceive('barcode')
        ->with('0000000000000')
        ->once()
        ->andReturn([]);

    livewire(CreateItem::class)
        ->fillForm([
            'name' => null,
            'description' => null,
        ])
        ->set('data.barcode', '0000000000000')
        ->assertNotified();
});

test('barcode lookup shows info when all fields already populated', function (): void {
    OpenFoodFacts::shouldReceive('barcode')
        ->with('3017620422003')
        ->once()
        ->andReturn([
            'product_name' => 'Nutella',
            'generic_name' => 'Hazelnut spread',
            'categories_hierarchy' => ['en:spreads', 'en:hazelnut-spreads'],
        ]);

    livewire(CreateItem::class)
        ->fillForm([
            'name' => 'Pre-filled Name',
            'description' => 'Pre-filled Description',
            'tags' => ['existing-tag'],
        ])
        ->set('data.barcode', '3017620422003')
        ->assertNotified();
});

test('barcode lookup ignores empty state that equals old value', function (): void {
    livewire(CreateItem::class)
        ->fillForm([
            'name' => null,
            'description' => null,
        ])
        ->set('data.barcode', null)
        ->assertNotNotified();
});

test('barcode lookup handles exception gracefully', function (): void {
    OpenFoodFacts::shouldReceive('barcode')
        ->with('3017620422003')
        ->once()
        ->andThrow(new RuntimeException('API unavailable'));

    livewire(CreateItem::class)
        ->fillForm([
            'name' => null,
            'description' => null,
        ])
        ->set('data.barcode', '3017620422003')
        ->assertNotNotified();
});

test('barcode lookup handles exception with custom exception type', function (): void {
    OpenFoodFacts::shouldReceive('barcode')
        ->with('3017620422003')
        ->once()
        ->andThrow(new Exception('Connection timeout'));

    livewire(CreateItem::class)
        ->fillForm([
            'name' => null,
            'description' => null,
        ])
        ->set('data.barcode', '3017620422003')
        ->assertNotNotified();
});

test('tags input shows suggestions from existing items', function (): void {
    Item::factory()->create(['tags' => ['Promotion', 'Healthy', 'Dairy']]);
    Item::factory()->create(['tags' => ['Promotion', 'Snacks']]);

    livewire(CreateItem::class)
        ->assertOk()
        ->assertFormFieldExists('tags');
});

test('tags input suggestions empty when no items have tags', function (): void {
    Item::factory()->create(['tags' => null]);

    livewire(CreateItem::class)
        ->assertOk()
        ->assertFormFieldExists('tags');
});

test('barcode lookup handles categories with non-array value', function (): void {
    OpenFoodFacts::shouldReceive('barcode')
        ->with('3017620422003')
        ->once()
        ->andReturn([
            'product_name' => 'Nutella',
            'generic_name' => 'Hazelnut spread',
            'categories_hierarchy' => 'not-an-array',
        ]);

    livewire(CreateItem::class)
        ->fillForm([
            'name' => null,
            'description' => null,
            'tags' => [],
        ])
        ->set('data.barcode', '3017620422003')
        ->assertSchemaStateSet([
            'name' => 'Nutella',
            'description' => 'Hazelnut spread',
            'tags' => [],
        ])
        ->assertNotified();
});
