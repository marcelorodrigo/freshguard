<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Resources;

use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Locations\Pages\CreateLocation;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Filament\Resources\Locations\Pages\ListLocations;
use App\Filament\Resources\Locations\Pages\ViewLocation;
use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Filament\Resources\Locations\Schemas\LocationInfolist;
use App\Filament\Resources\Locations\Tables\LocationsTable;
use App\Models\Location;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Testing library and framework: PHPUnit
 *
 * These tests validate the public/static interfaces on LocationResource:
 *  - Model binding (getModel / static $model)
 *  - Navigation icon exposure
 *  - Schema and Table configuration return types
 *  - Relations and Pages registry structure
 *
 * External Filament internals are not executed; we assert contracts and structure.
 */
final class LocationResourceTest extends TestCase
{
    public function test_model_is_bound_to_location(): void
    {
        // Prefer using public API if available (getModel), fall back to reflection on property.
        $model = null;

        if (method_exists(LocationResource::class, 'getModel')) {
            $model = LocationResource::getModel();
        } else {
            $ref = new ReflectionClass(LocationResource::class);
            $prop = $ref->getProperty('model');
            $prop->setAccessible(true);
            $model = $prop->getValue();
        }

        $this->assertSame(Location::class, $model, 'LocationResource model should be App\Models\Location.');
    }

    public function test_navigation_icon_is_heroicon_outlined_rectangle_stack(): void
    {
        // Prefer public accessor if present
        $icon = null;

        if (method_exists(LocationResource::class, 'getNavigationIcon')) {
            $icon = LocationResource::getNavigationIcon();
        } else {
            $ref = new ReflectionClass(LocationResource::class);
            $prop = $ref->getProperty('navigationIcon');
            $prop->setAccessible(true);
            $icon = $prop->getValue();
        }

        $this->assertNotNull($icon);
        // Accept either enum or string value depending on Filament version.
        $expected = Heroicon::OutlinedRectangleStack;
        if ($icon instanceof \BackedEnum) {
            $this->assertSame($expected->value, $icon->value);
        } else {
            $this->assertSame($expected, $icon);
        }
    }

    public function test_form_returns_schema_configured_by_location_form(): void
    {
        // Build a minimal Schema double if needed; many Filament APIs accept a Schema instance directly.
        // We'll instantiate a basic Schema via Reflection if constructor is non-public.
        $schema = $this->instantiateSchema();

        $result = LocationResource::form($schema);

        $this->assertInstanceOf(Schema::class, $result, 'form() must return a Filament Schema instance');

        // Basic sanity: ensure the static configurator exists and is callable
        $this->assertTrue(
            method_exists(LocationForm::class, 'configure'),
            'LocationForm::configure should exist'
        );
    }

    public function test_infolist_returns_schema_configured_by_location_infolist(): void
    {
        $schema = $this->instantiateSchema();

        $result = LocationResource::infolist($schema);

        $this->assertInstanceOf(Schema::class, $result, 'infolist() must return a Filament Schema instance');

        $this->assertTrue(
            method_exists(LocationInfolist::class, 'configure'),
            'LocationInfolist::configure should exist'
        );
    }

    public function test_table_returns_table_configured_by_locations_table(): void
    {
        $table = $this->instantiateTable();

        $result = LocationResource::table($table);

        $this->assertInstanceOf(Table::class, $result, 'table() must return a Filament Table instance');

        $this->assertTrue(
            method_exists(LocationsTable::class, 'configure'),
            'LocationsTable::configure should exist'
        );
    }

    public function test_relations_is_empty_array(): void
    {
        $relations = LocationResource::getRelations();
        $this->assertIsArray($relations);
        $this->assertSame([], $relations, 'getRelations() should return an empty array');
    }

    public function test_pages_definitions_include_crud_pages_with_expected_keys(): void
    {
        $pages = LocationResource::getPages();

        $this->assertIsArray($pages);
        foreach (['index', 'create', 'view', 'edit'] as $key) {
            $this->assertArrayHasKey($key, $pages, "getPages() should define the '$key' page");
        }

        // We cannot rely on specific Filament internal PageRoute types without the framework loaded,
        // but we can assert the static page classes referenced exist.
        $this->assertTrue(class_exists(ListLocations::class), 'ListLocations page class should exist');
        $this->assertTrue(class_exists(CreateLocation::class), 'CreateLocation page class should exist');
        $this->assertTrue(class_exists(ViewLocation::class), 'ViewLocation page class should exist');
        $this->assertTrue(class_exists(EditLocation::class), 'EditLocation page class should exist');
    }

    // --- Helpers ------------------------------------------------------------------------------

    private function instantiateSchema(): Schema
    {
        // Try default instantiation first
        try {
            return new Schema();
        } catch (\Throwable $e) {
            // Fallback: instantiate without constructor if Schema requires container/DI
            $ref = new ReflectionClass(Schema::class);
            return $ref->newInstanceWithoutConstructor();
        }
    }

    private function instantiateTable(): Table
    {
        try {
            return new Table();
        } catch (\Throwable $e) {
            $ref = new ReflectionClass(Table::class);
            return $ref->newInstanceWithoutConstructor();
        }
    }
}