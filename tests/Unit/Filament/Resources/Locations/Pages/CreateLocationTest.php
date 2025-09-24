<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Locations\Pages\CreateLocation;
use Filament\Resources\Pages\CreateRecord;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CreateLocationTest extends TestCase
{
    public function test_it_extends_filament_create_record_page(): void
    {
        $this->assertTrue(
            is_subclass_of(CreateLocation::class, CreateRecord::class),
            'CreateLocation must extend Filament\\Resources\\Pages\\CreateRecord'
        );
    }

    public function test_it_binds_to_location_resource_class(): void
    {
        // Use reflection to access the protected static $resource property value
        $ref = new ReflectionClass(CreateLocation::class);

        $this->assertTrue(
            $ref->hasProperty('resource'),
            'CreateLocation must declare protected static $resource'
        );

        $prop = $ref->getProperty('resource');
        $this->assertTrue($prop->isStatic(), 'CreateLocation::$resource must be static');
        $prop->setAccessible(true);

        $value = $prop->getValue();
        $this->assertSame(
            LocationResource::class,
            $value,
            'CreateLocation::$resource must reference LocationResource::class'
        );
    }

    public function test_class_is_instantiable_without_constructor_args(): void
    {
        // Some Filament pages can be constructed without args for unit validation;
        // if Filament internals require context, at least ensure reflection can create an instance.
        $ref = new ReflectionClass(CreateLocation::class);

        // Prefer normal instantiation first
        $instance = null;
        try {
            $instance = $ref->newInstanceWithoutConstructor();
        } catch (\Throwable $e) {
            $this->fail('CreateLocation should be instantiable via reflection without constructor: ' . $e->getMessage());
        }

        $this->assertInstanceOf(CreateLocation::class, $instance);
    }

    public function test_class_has_no_unexpected_public_methods(): void
    {
        // Guard-rail: ensure no unexpected public API has been added accidentally.
        $ref = new ReflectionClass(CreateLocation::class);

        // These are inherited framework methods; we only assert that no new public methods were added on this class itself.
        $declaredHere = array_values(array_filter(
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn(\ReflectionMethod $m) => $m->getDeclaringClass()->getName() === CreateLocation::class
        ));

        $this->assertSame(
            [],
            array_map(static fn(\ReflectionMethod $m) => $m->getName(), $declaredHere),
            'CreateLocation should not declare additional public methods; keep page lean and rely on Resource configuration.'
        );
    }
}