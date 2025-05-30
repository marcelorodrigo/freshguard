<?php

namespace Tests\Unit\Models;

use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TagTest extends TestCase
{
    use RefreshDatabase;

    public function test_uuid_is_generated_on_creation(): void
    {
        $tag = Tag::factory()->create();

        $this->assertNotNull($tag->id);
        $this->assertTrue(Str::isUuid($tag->id));
    }

    public function test_fillable_attributes(): void
    {
        $tag = new Tag();

        $this->assertEquals([
            'name',
            'description',
        ], $tag->getFillable());
    }

    public function test_incrementing_is_false(): void
    {
        $tag = new Tag();

        $this->assertFalse($tag->getIncrementing());
    }

    public function test_key_type_is_string(): void
    {
        $tag = new Tag();

        $this->assertEquals('string', $tag->getKeyType());
    }

    public function test_factory_creates_valid_tag(): void
    {
        $tag = Tag::factory()->create();

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => $tag->name,
        ]);
    }

    public function test_factory_creates_tag_with_optional_description(): void
    {
        // Create tag with description
        $tagWithDescription = Tag::factory()->create([
            'description' => 'Test description',
        ]);

        $this->assertNotNull($tagWithDescription->description);
        $this->assertEquals('Test description', $tagWithDescription->description);

        // Create tag without description
        $tagWithoutDescription = Tag::factory()->create([
            'description' => null,
        ]);

        $this->assertNull($tagWithoutDescription->description);
    }

    public function test_creates_with_minimal_attributes(): void
    {
        $tag = Tag::create([
            'name' => 'Test Tag',
        ]);

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => 'Test Tag',
        ]);
        $this->assertNull($tag->description);
    }

    public function test_updates_attributes(): void
    {
        $tag = Tag::factory()->create();
        $originalId = $tag->id;

        $tag->update([
            'name' => 'Updated Name',
            'description' => 'Updated Description',
        ]);

        $this->assertEquals($originalId, $tag->id);
        $this->assertEquals('Updated Name', $tag->name);
        $this->assertEquals('Updated Description', $tag->description);

        $this->assertDatabaseHas('tags', [
            'id' => $originalId,
            'name' => 'Updated Name',
            'description' => 'Updated Description',
        ]);
    }

    public function test_deletes_tag(): void
    {
        $tag = Tag::factory()->create();
        $tagId = $tag->id;

        $tag->delete();

        $this->assertDatabaseMissing('tags', [
            'id' => $tagId,
        ]);
    }
}
