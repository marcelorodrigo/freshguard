<?php

namespace Tests\Unit\Filament\Resources\Locations\Schemas;

use App\Filament\Resources\Locations\Schemas\LocationForm;
use Filament\Schemas\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LocationFormTest extends TestCase
{
    #[Test]
    public function should_have_configure_method(): void
    {
        $this->assertTrue(method_exists(LocationForm::class, 'configure'));

        $reflection = new \ReflectionMethod(LocationForm::class, 'configure');
        $this->assertTrue($reflection->isStatic());
        $this->assertTrue($reflection->isPublic());
    }

    #[Test]
    public function should_accept_schema_parameter(): void
    {
        $reflection = new \ReflectionMethod(LocationForm::class, 'configure');
        $parameters = $reflection->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame('schema', $parameters[0]->getName());
        $this->assertSame(Schema::class, $parameters[0]->getType()?->getName());
    }

    #[Test]
    public function should_return_schema_type(): void
    {
        $reflection = new \ReflectionMethod(LocationForm::class, 'configure');
        $returnType = $reflection->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame(Schema::class, $returnType->getName());
    }
}
