<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Resources\Locations\Schemas;

use App\Filament\Resources\Locations\Schemas\LocationInfolist;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Testing library/framework: PHPUnit
 * @covers \App\Filament\Resources\Locations\Schemas\LocationInfolist
 */
final class LocationInfolistTest extends TestCase
{
    private function extractComponents(Schema $schema): array
    {
        if (method_exists($schema, 'getComponents')) {
            return $schema->getComponents();
        }

        if (method_exists($schema, 'toArray')) {
            $arr = $schema->toArray();
            if (isset($arr['components']) && is_array($arr['components'])) {
                return $arr['components'];
            }
        }

        $ref = new \ReflectionClass($schema);
        foreach (['components', 'schema', 'items', 'children', 'entries'] as $prop) {
            if ($ref->hasProperty($prop)) {
                $property = $ref->getProperty($prop);
                $property->setAccessible(true);
                $value = $property->getValue($schema);
                if (is_array($value)) {
                    return $value;
                }
            }
        }

        return [];
    }

    private function entryName(object $entry): ?string
    {
        foreach (['getName', 'getColumn', 'getStatePath'] as $method) {
            if (method_exists($entry, $method)) {
                $val = $entry->$method();
                if (is_string($val)) {
                    return $val;
                }
            }
        }

        foreach (['name', 'column', 'statePath'] as $prop) {
            if (property_exists($entry, $prop) && is_string($entry->$prop)) {
                return $entry->$prop;
            }
        }

        if (method_exists($entry, 'toArray')) {
            $arr = $entry->toArray();
            foreach (['name', 'column', 'statePath'] as $k) {
                if (isset($arr[$k]) && is_string($arr[$k])) {
                    return $arr[$k];
                }
            }
        }

        return null;
    }

    private function entryLabel(TextEntry $entry): ?string
    {
        if (method_exists($entry, 'getLabel')) {
            return $entry->getLabel();
        }
        if (property_exists($entry, 'label') && is_string($entry->label)) {
            return $entry->label;
        }
        if (method_exists($entry, 'toArray')) {
            $arr = $entry->toArray();
            if (isset($arr['label'])) {
                return $arr['label'];
            }
        }
        return null;
    }

    private function entryIsDateTime(TextEntry $entry): bool
    {
        foreach (['isDateTime', 'getIsDateTime'] as $method) {
            if (method_exists($entry, $method)) {
                $val = $entry->$method();
                if (is_bool($val)) {
                    return $val;
                }
            }
        }

        if (method_exists($entry, 'toArray')) {
            $arr = $entry->toArray();
            foreach (['isDateTime', 'date', 'datetime'] as $key) {
                if (array_key_exists($key, $arr)) {
                    return (bool) $arr[$key];
                }
            }
            if (isset($arr['format']) && is_string($arr['format'])) {
                return true;
            }
        }

        $ref = new \ReflectionClass($entry);
        foreach (['isDateTime', 'date', 'datetime'] as $prop) {
            if ($ref->hasProperty($prop)) {
                $p = $ref->getProperty($prop);
                $p->setAccessible(true);
                $val = $p->getValue($entry);
                if (is_bool($val)) {
                    return $val;
                }
            }
        }

        return false;
    }

    public function test_configure_returns_same_schema_instance(): void
    {
        $schema = Schema::make();
        $configured = LocationInfolist::configure($schema);

        $this->assertInstanceOf(Schema::class, $configured);
        $this->assertSame($schema, $configured, 'configure should return same Schema instance for chaining');
    }

    public function test_registers_expected_text_entries_in_order(): void
    {
        $schema = Schema::make();
        $configured = LocationInfolist::configure($schema);

        $components = $this->extractComponents($configured);
        $this->assertCount(6, $components);

        $expected = ['id', 'name', 'description', 'parent.name', 'created_at', 'updated_at'];

        foreach ($components as $i => $component) {
            $this->assertInstanceOf(TextEntry::class, $component, "Component $i should be a TextEntry");
            $this->assertSame($expected[$i], $this->entryName($component));
        }
    }

    public function test_id_entry_has_label_ID(): void
    {
        $schema = Schema::make();
        $components = $this->extractComponents(LocationInfolist::configure($schema));

        /** @var TextEntry $id */
        $id = $components[0];
        $this->assertSame('ID', $this->entryLabel($id));
    }

    public function test_timestamp_entries_are_marked_as_datetime(): void
    {
        $schema = Schema::make();
        $components = $this->extractComponents(LocationInfolist::configure($schema));

        /** @var TextEntry $created */
        $created = $components[4];
        /** @var TextEntry $updated */
        $updated = $components[5];

        $this->assertTrue($this->entryIsDateTime($created), 'created_at should be dateTime');
        $this->assertTrue($this->entryIsDateTime($updated), 'updated_at should be dateTime');
    }

    public function test_parent_name_nested_property_is_supported(): void
    {
        $schema = Schema::make();
        $components = $this->extractComponents(LocationInfolist::configure($schema));

        /** @var TextEntry $parentName */
        $parentName = $components[3];
        $this->assertSame('parent.name', $this->entryName($parentName));
    }
}