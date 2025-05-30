<?php

namespace Tests\Unit\Models;

use App\Models\Item;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        $tag = Tag::factory()->create([
            'name' => 'Test Tag',
            'description' => null,
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

    public function test_tag_has_items_relationship(): void
    {
        $tag = new Tag();

        $this->assertInstanceOf(BelongsToMany::class, $tag->items());
        $this->assertInstanceOf(Collection::class, $tag->items);
    }

    public function test_tag_can_have_many_items(): void
    {
        $tag = Tag::factory()->create();
        $items = Item::factory()->count(3)->create();

        $tag->items()->attach($items);

        $this->assertCount(3, $tag->items);
        foreach ($items as $item) {
            $this->assertTrue($tag->items->contains($item));
        }
    }

    public function test_items_can_be_detached_from_tag(): void
    {
        $tag = Tag::factory()->create();
        $items = Item::factory()->count(3)->create();

        $tag->items()->attach($items);
        $this->assertCount(3, $tag->items);

        $tag->items()->detach($items->first());
        $tag->refresh();

        $this->assertCount(2, $tag->items);
        $this->assertFalse($tag->items->contains($items->first()));
        $this->assertTrue($tag->items->contains($items[1]));
        $this->assertTrue($tag->items->contains($items[2]));
    }

    public function test_deleting_tag_doesnt_delete_linked_items(): void
    {
        $tag = Tag::factory()->create();
        $item = Item::factory()->create();

        $tag->items()->attach($item);
        $itemId = $item->id;
        $tagId = $tag->id;

        $tag->delete();

        $this->assertDatabaseMissing('tags', ['id' => $tagId]);
        $this->assertDatabaseHas('items', ['id' => $itemId]);
        $this->assertDatabaseMissing('item_tag', [
            'item_id' => $itemId,
            'tag_id' => $tagId
        ]);
    }
}
