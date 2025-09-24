<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

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
use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestCase;

final class LocationResourceIntegrationTest extends TestCase
{
    /** @test */
    public function it_uses_the_location_model(): void
    {
        $ref = new \ReflectionClass(LocationResource::class);
        $prop = $ref->getProperty('model');
        $prop->setAccessible(true);
        $this->assertSame(Location::class, $prop->getValue(null));
    }

    /** @test */
    public function it_sets_the_expected_navigation_icon(): void
    {
        $ref = new \ReflectionClass(LocationResource::class);
        $prop = $ref->getProperty('navigationIcon');
        $prop->setAccessible(true);
        $this->assertSame(Heroicon::OutlinedRectangleStack, $prop->getValue(null));
    }

    /** @test */
    public function form_returns_a_schema_configured_by_location_form(): void
    {
        $schema = new Schema();
        $result = LocationResource::form($schema);

        $this->assertInstanceOf(Schema::class, $result, 'form() should return a Filament Schema');
        // Smoke-check that configurator class exists to avoid broken refs
        $this->assertTrue(class_exists(LocationForm::class), 'LocationForm configurator class should exist');
    }

    /** @test */
    public function infolist_returns_a_schema_configured_by_location_infolist(): void
    {
        $schema = new Schema();
        $result = LocationResource::infolist($schema);

        $this->assertInstanceOf(Schema::class, $result, 'infolist() should return a Filament Schema');
        $this->assertTrue(class_exists(LocationInfolist::class), 'LocationInfolist configurator class should exist');
    }

    /** @test */
    public function table_returns_a_table_configured_by_locations_table(): void
    {
        $table = new Table();
        $result = LocationResource::table($table);

        $this->assertInstanceOf(Table::class, $result, 'table() should return a Filament Table');
        $this->assertTrue(class_exists(LocationsTable::class), 'LocationsTable configurator class should exist');
    }

    /** @test */
    public function get_relations_is_currently_empty(): void
    {
        $relations = LocationResource::getRelations();
        $this->assertIsArray($relations);
        $this->assertCount(0, $relations, 'Expected no relations for LocationResource at this time.');
    }

    /** @test */
    public function get_pages_defines_expected_routes_and_pages(): void
    {
        $pages = LocationResource::getPages();

        // Assert expected keys exist
        foreach (['index', 'create', 'view', 'edit'] as $key) {
            $this->assertArrayHasKey($key, $pages, "Missing '$key' page mapping");
        }

        // Ensure referenced page classes exist
        $this->assertTrue(class_exists(ListLocations::class), 'ListLocations page class should exist');
        $this->assertTrue(class_exists(CreateLocation::class), 'CreateLocation page class should exist');
        $this->assertTrue(class_exists(ViewLocation::class), 'ViewLocation page class should exist');
        $this->assertTrue(class_exists(EditLocation::class), 'EditLocation page class should exist');

        // Basic smoke: the route(...) helpers typically return strings; ensure non-empty
        foreach (['index','create','view','edit'] as $key) {
            $this->assertIsString($pages[$key], "Page mapping for '$key' should be a string route");
            $this->assertNotSame('', $pages[$key], "Page mapping for '$key' should not be empty");
        }
    }

    /** @test */
    public function location_resource_is_reflection_safe_for_static_properties(): void
    {
        $ref = new \ReflectionClass(LocationResource::class);
        foreach (['model', 'navigationIcon'] as $propName) {
            $this->assertTrue($ref->hasProperty($propName), "Static property '$propName' missing");
            $prop = $ref->getProperty($propName);
            $this->assertTrue($prop->isStatic(), "Property '$propName' should be static");
            $this->assertTrue($prop->isProtected() || $prop->isPublic(), "Property '$propName' should not be private");
        }
    }

    /** @test */
    public function public_interfaces_handle_unexpected_input_gracefully(): void
    {
        // form/infolist/table should accept the expected types and not throw for basic construction.
        $this->expectNotToPerformAssertions();

        // Guard clauses: ensure type errors are thrown when wrong types are passed (PHP will type error)
        try {
            /** @phpstan-ignore-next-line */
            LocationResource::form(new \stdClass());
            throw new ExpectationFailedException('form() should not accept stdClass');
        } catch (\TypeError $e) {
            // expected
        }

        try {
            /** @phpstan-ignore-next-line */
            LocationResource::infolist(new \stdClass());
            throw new ExpectationFailedException('infolist() should not accept stdClass');
        } catch (\TypeError $e) {
            // expected
        }

        try {
            /** @phpstan-ignore-next-line */
            LocationResource::table(new \stdClass());
            throw new ExpectationFailedException('table() should not accept stdClass');
        } catch (\TypeError $e) {
            // expected
        }
    }
}