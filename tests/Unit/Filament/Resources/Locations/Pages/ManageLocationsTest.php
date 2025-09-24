<?php

namespace Tests\Unit\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Locations\Pages\ManageLocations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ManageLocationsTest extends TestCase
{
    #[Test]
    public function should_return_correct_resource_class(): void
    {
        $resourceProperty = new \ReflectionProperty(ManageLocations::class, 'resource');

        $this->assertSame(LocationResource::class, $resourceProperty->getDefaultValue());
    }

    #[Test]
    public function should_have_get_header_actions_method(): void
    {
        $this->assertTrue(method_exists(ManageLocations::class, 'getHeaderActions'));

        $reflection = new \ReflectionMethod(ManageLocations::class, 'getHeaderActions');
        $this->assertTrue($reflection->isProtected());

        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('array', $returnType->getName());
    }

    #[Test]
    public function should_extend_manage_records(): void
    {
        $reflection = new \ReflectionClass(ManageLocations::class);
        $parentClass = $reflection->getParentClass();

        $this->assertNotFalse($parentClass);
        $this->assertSame('Filament\Resources\Pages\ManageRecords', $parentClass->getName());
    }
}
