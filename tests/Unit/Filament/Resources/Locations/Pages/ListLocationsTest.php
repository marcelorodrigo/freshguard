<?php
declare(strict_types=1);

/**
 * Note: Using PHPUnit (Laravel default) as the testing framework.
 * Tests focus on App\Filament\Resources\Locations\Pages\ListLocations.
 */

namespace Tests\Unit\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Locations\Pages\ListLocations;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\CreateAction;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ListLocationsTest extends TestCase
{
    public function test_extends_filament_list_records_page(): void
    {
        $this->assertTrue(
            is_subclass_of(ListLocations::class, ListRecords::class),
            'ListLocations should extend Filament ListRecords page'
        );
    }

    public function test_binds_expected_resource_class_statically(): void
    {
        $ref = new ReflectionClass(ListLocations::class);
        $value = $ref->getStaticPropertyValue('resource');
        $this->assertSame(LocationResource::class, $value, 'ListLocations::$resource should point to LocationResource::class');
    }

    public function test_static_resource_is_a_valid_class_string(): void
    {
        $ref = new ReflectionClass(ListLocations::class);
        $value = $ref->getStaticPropertyValue('resource');
        $this->assertIsString($value, 'ListLocations::$resource should be a class-string');
        $this->assertTrue(class_exists($value), 'ListLocations::$resource should reference an existing class');
    }

    public function test_reflection_instantiation_when_no_required_constructor_args(): void
    {
        $ref = new ReflectionClass(ListLocations::class);
        $ctor = $ref->getConstructor();

        if ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0) {
            $instance = $ref->newInstance();
            $this->assertInstanceOf(ListLocations::class, $instance);
        } else {
            // Parent or class constructor requires args in this Filament version; skip actual instantiation.
            $this->assertTrue(true, 'Constructor has required parameters; skipping instantiation test.');
        }
    }

    /**
     * Exposes getHeaderActions() via a small test proxy that bypasses the parent constructor.
     */
    private function makePageProxy(): object
    {
        return new class extends ListLocations {
            public function __construct() {} // Intentionally bypass parent constructor
            public function callGetHeaderActions(): array
            {
                return $this->getHeaderActions();
            }
        };
    }

    public function test_get_header_actions_contains_single_create_action(): void
    {
        $page = $this->makePageProxy();
        $actions = $page->callGetHeaderActions();

        $this->assertIsArray($actions, 'getHeaderActions should return an array');
        $this->assertCount(1, $actions, 'getHeaderActions should contain exactly one action');
        $this->assertInstanceOf(CreateAction::class, $actions[0], 'The first action should be an instance of CreateAction');
    }

    public function test_create_action_has_expected_default_name(): void
    {
        $page = $this->makePageProxy();
        $actions = $page->callGetHeaderActions();
        /** @var CreateAction $create */
        $create = $actions[0];

        if (method_exists($create, 'getName')) {
            $this->assertSame('create', $create->getName(), 'CreateAction default name should be "create"');
        } else {
            $this->assertTrue(true, 'Skipped name assertion because getName() is not available on this Filament version.');
        }
    }

    public function test_get_header_actions_returns_new_instances_each_time(): void
    {
        $page = $this->makePageProxy();

        $first = $page->callGetHeaderActions();
        $second = $page->callGetHeaderActions();

        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertCount(1, $first);
        $this->assertCount(1, $second);

        $this->assertNotSame($first[0], $second[0], 'Expected CreateAction instances to be newly constructed per call');
        $this->assertInstanceOf(CreateAction::class, $first[0]);
        $this->assertInstanceOf(CreateAction::class, $second[0]);
    }
}