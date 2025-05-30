<?php

namespace Tests\Unit\Models;

use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_uuid_is_generated_on_creation(): void
    {
        $location = Location::factory()->create();

        $this->assertNotNull($location->id);
        $this->assertTrue(Str::isUuid($location->id));
    }

    public function test_fillable_attributes(): void
    {
        $location = new Location();

        $this->assertEquals([
            'name',
            'description',
            'parent_id',
        ], $location->getFillable());
    }

    public function test_incrementing_is_false(): void
    {
        $location = new Location();

        $this->assertFalse($location->getIncrementing());
    }

    public function test_key_type_is_string(): void
    {
        $location = new Location();

        $this->assertEquals('string', $location->getKeyType());
    }

    public function test_parent_relation(): void
    {
        $location = new Location();

        $this->assertInstanceOf(BelongsTo::class, $location->parent());
    }

    public function test_children_relation(): void
    {
        $location = new Location();

        $this->assertInstanceOf(HasMany::class, $location->children());
    }

    public function test_parent_child_relationship(): void
    {
        // Create parent location
        $parent = Location::factory()->create([
            'name' => 'Parent Location'
        ]);

        // Create child location with parent relation
        $child = Location::factory()->create([
            'name' => 'Child Location',
            'parent_id' => $parent->id
        ]);

        // Create another child
        $anotherChild = Location::factory()->create([
            'name' => 'Another Child Location',
            'parent_id' => $parent->id
        ]);

        // Test parent-child relationship (from child's perspective)
        $this->assertEquals($parent->id, $child->parent->id);
        $this->assertEquals('Parent Location', $child->parent->name);

        // Test parent-children relationship (from parent's perspective)
        $this->assertInstanceOf(Collection::class, $parent->children);
        $this->assertCount(2, $parent->children);
        $this->assertTrue($parent->children->contains($child));
        $this->assertTrue($parent->children->contains($anotherChild));
    }

    public function test_can_create_location_with_null_description(): void
    {
        $location = Location::factory()->create([
            'name' => 'Test Location',
            'description' => null
        ]);

        $this->assertNull($location->description);
    }

    public function test_can_create_location_with_null_parent(): void
    {
        $location = Location::factory()->create([
            'name' => 'Root Location',
            'parent_id' => null
        ]);

        $this->assertNull($location->parent_id);
        $this->assertNull($location->parent);
    }

    public function test_can_create_location_with_factory_with_parent_method(): void
    {
        $parent = Location::factory()->create();
        $child = Location::factory()->withParent($parent)->create();

        $this->assertEquals($parent->id, $child->parent_id);
    }

    public function test_can_update_location_attributes(): void
    {
        $location = Location::factory()->create([
            'name' => 'Original Name',
            'description' => 'Original Description'
        ]);

        $location->name = 'Updated Name';
        $location->description = 'Updated Description';
        $location->save();

        $this->assertEquals('Updated Name', $location->fresh()->name);
        $this->assertEquals('Updated Description', $location->fresh()->description);
    }

    public function test_can_save_location_with_emoji_in_name_and_description(): void
    {
        $location = Location::factory()->create([
            'name' => '🏠 Living Room',
            'description' => 'Cozy place for relaxing 😊'
        ]);

        $this->assertEquals('🏠 Living Room', $location->name);
        $this->assertEquals('Cozy place for relaxing 😊', $location->description);
    }
}
