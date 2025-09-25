<?php

namespace Tests\Unit\Filament\Resources\Locations;

use App\Filament\Resources\Locations\LocationResource;
use App\Models\Location;
use Filament\Support\Icons\Heroicon;
use PHPUnit\Framework\TestCase;

class LocationResourceTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function should_return_correct_model_class(): void
    {
        $resource = new LocationResource;
        $modelProperty = new \ReflectionProperty(LocationResource::class, 'model');

        $this->assertSame(Location::class, $modelProperty->getDefaultValue());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function should_return_correct_navigation_icon(): void
    {
        $resource = new LocationResource;
        $iconProperty = new \ReflectionProperty(LocationResource::class, 'navigationIcon');

        $this->assertSame(Heroicon::OutlinedHomeModern, $iconProperty->getDefaultValue());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function should_return_correct_record_title_attribute(): void
    {
        $resource = new LocationResource;
        $titleProperty = new \ReflectionProperty(LocationResource::class, 'recordTitleAttribute');

        $this->assertSame('name', $titleProperty->getDefaultValue());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function should_have_form_method(): void
    {
        $this->assertTrue(method_exists(LocationResource::class, 'form'));

        $reflection = new \ReflectionMethod(LocationResource::class, 'form');
        $this->assertTrue($reflection->isStatic());
        $this->assertTrue($reflection->isPublic());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function should_have_table_method(): void
    {
        $this->assertTrue(method_exists(LocationResource::class, 'table'));

        $reflection = new \ReflectionMethod(LocationResource::class, 'table');
        $this->assertTrue($reflection->isStatic());
        $this->assertTrue($reflection->isPublic());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function should_return_correct_pages(): void
    {
        $pages = LocationResource::getPages();

        $this->assertIsArray($pages);
        $this->assertArrayHasKey('index', $pages);
    }
}
