<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_create_a_location_with_factory(): void
    {
        $location = Location::factory()->create();
        $this->assertInstanceOf(Location::class, $location);
        $this->assertNotNull($location->id);
        $this->assertIsString($location->id);
    }

    public function test_it_has_uuid_as_primary_key(): void
    {
        $location = Location::factory()->create();
        $this->assertIsString($location->id);
        $this->assertEquals(36, strlen($location->id)); // UUID length
    }

    public function test_it_can_have_a_parent(): void
    {
        $parent = Location::factory()->create();
        $child = Location::factory()->create(['parent_id' => $parent->id]);
        $this->assertTrue($child->parent->is($parent));
    }

    public function test_it_can_have_children(): void
    {
        $parent = Location::factory()->create();
        $child1 = Location::factory()->create(['parent_id' => $parent->id]);
        $child2 = Location::factory()->create(['parent_id' => $parent->id]);
        $this->assertCount(2, $parent->children);
        $this->assertTrue($parent->children->contains($child1));
        $this->assertTrue($parent->children->contains($child2));
    }

    public function test_it_can_have_no_parent(): void
    {
        $location = Location::factory()->create(['parent_id' => null]);
        $this->assertNull($location->parent);
    }

    public function test_it_can_have_no_children(): void
    {
        $location = Location::factory()->create();
        $this->assertCount(0, $location->children);
    }

    public function test_fillable_attributes(): void
    {
        $data = [
            'name' => 'Test Location',
            'description' => 'Test Description',
            'expiration_notify_days' => 10,
            'parent_id' => null,
        ];
        $location = Location::create($data);
        $this->assertEquals('Test Location', $location->name);
        $this->assertEquals('Test Description', $location->description);
        $this->assertEquals(10, $location->expiration_notify_days);
        $this->assertNull($location->parent_id);
    }
}
