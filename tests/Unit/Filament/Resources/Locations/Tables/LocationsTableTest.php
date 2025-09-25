<?php

namespace Tests\Unit\Filament\Resources\Locations\Tables;

use App\Filament\Resources\Locations\Tables\LocationsTable;
use Filament\Tables\Table;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LocationsTableTest extends TestCase
{
    #[Test]
    public function should_have_configure_method(): void
    {
        $this->assertTrue(method_exists(LocationsTable::class, 'configure'));

        $reflection = new \ReflectionMethod(LocationsTable::class, 'configure');
        $this->assertTrue($reflection->isStatic());
        $this->assertTrue($reflection->isPublic());
    }

    #[Test]
    public function should_accept_table_parameter(): void
    {
        $reflection = new \ReflectionMethod(LocationsTable::class, 'configure');
        $parameters = $reflection->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame('table', $parameters[0]->getName());
        $this->assertSame(Table::class, $parameters[0]->getType()?->getName());
    }

    #[Test]
    public function should_return_table_type(): void
    {
        $reflection = new \ReflectionMethod(LocationsTable::class, 'configure');
        $returnType = $reflection->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame(Table::class, $returnType->getName());
    }
}
