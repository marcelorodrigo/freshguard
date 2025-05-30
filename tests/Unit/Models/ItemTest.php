<?php

namespace Tests\Unit\Models;

use App\Models\Item;
use App\Models\Location;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_uuid_is_generated_on_creation(): void
    {
        $item = Item::factory()->create();

        $this->assertNotNull($item->id);
        $this->assertTrue(Str::isUuid($item->id));
    }

    public function test_fillable_attributes(): void
    {
        $item = new Item();

        $this->assertEquals([
            'location_id',
            'name',
            'description',
        ], $item->getFillable());
    }

    public function test_incrementing_is_false(): void
    {
        $item = new Item();

        $this->assertFalse($item->getIncrementing());
    }

    public function test_key_type_is_string(): void
    {
        $item = new Item();

        $this->assertEquals('string', $item->getKeyType());
    }

    public function test_factory_creates_valid_item(): void
    {
        $item = Item::factory()->create();

        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'name' => $item->name,
            'location_id' => $item->location_id,
        ]);
    }

    public function test_factory_creates_item_with_optional_description(): void
    {
        // Create item with description
        $itemWithDescription = Item::factory()->create();
        $this->assertNotNull($itemWithDescription->description);

        // Create item without description
        $itemWithoutDescription = Item::factory()->withoutDescription()->create();
        $this->assertNull($itemWithoutDescription->description);
    }

    public function test_creates_with_minimal_attributes(): void
    {
        $location = Location::factory()->create();

        $item = Item::create([
            'location_id' => $location->id,
            'name' => 'Test Item',
        ]);

        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'name' => 'Test Item',
            'location_id' => $location->id,
        ]);
        $this->assertNull($item->description);
    }

    public function test_updates_attributes(): void
    {
        $item = Item::factory()->create();
        $originalId = $item->id;
        $newLocation = Location::factory()->create();

        $item->update([
            'name' => 'Updated Name',
            'description' => 'Updated Description',
            'location_id' => $newLocation->id,
        ]);

        $this->assertEquals($originalId, $item->id);
        $this->assertEquals('Updated Name', $item->name);
        $this->assertEquals('Updated Description', $item->description);
        $this->assertEquals($newLocation->id, $item->location_id);

        $this->assertDatabaseHas('items', [
            'id' => $originalId,
            'name' => 'Updated Name',
            'description' => 'Updated Description',
            'location_id' => $newLocation->id,
        ]);
    }

    public function test_deletes_item(): void
    {
        $item = Item::factory()->create();
        $itemId = $item->id;

        $item->delete();

        $this->assertDatabaseMissing('items', [
            'id' => $itemId,
        ]);
    }

    public function test_has_location_relationship(): void
    {
        $item = new Item();

        $this->assertInstanceOf(BelongsTo::class, $item->location());
    }

    public function test_belongs_to_location(): void
    {
        $location = Location::factory()->create();
        $item = Item::factory()->for($location)->create();

        $this->assertInstanceOf(Location::class, $item->location);
        $this->assertEquals($location->id, $item->location->id);
    }

    public function test_has_tags_relationship(): void
    {
        $item = new Item();

        $this->assertInstanceOf(BelongsToMany::class, $item->tags());
        $this->assertInstanceOf(Collection::class, $item->tags);
    }

    public function test_can_have_many_tags(): void
    {
        $item = Item::factory()->create();
        $tags = Tag::factory()->count(3)->create();

        $item->tags()->attach($tags);

        $this->assertCount(3, $item->tags);
        foreach ($tags as $tag) {
            $this->assertTrue($item->tags->contains($tag));
        }
    }

    public function test_tags_can_be_detached(): void
    {
        $item = Item::factory()->create();
        $tags = Tag::factory()->count(3)->create();

        $item->tags()->attach($tags);
        $this->assertCount(3, $item->tags);

        $item->tags()->detach($tags->first());
        $item->refresh();

        $this->assertCount(2, $item->tags);
        $this->assertFalse($item->tags->contains($tags->first()));
        $this->assertTrue($item->tags->contains($tags[1]));
        $this->assertTrue($item->tags->contains($tags[2]));
    }

    public function test_deleting_item_doesnt_delete_tags(): void
    {
        $item = Item::factory()->create();
        $tag = Tag::factory()->create();

        $item->tags()->attach($tag);
        $itemId = $item->id;
        $tagId = $tag->id;

        $item->delete();

        $this->assertDatabaseMissing('items', ['id' => $itemId]);
        $this->assertDatabaseHas('tags', ['id' => $tagId]);
        $this->assertDatabaseMissing('item_tag', [
            'item_id' => $itemId,
            'tag_id' => $tagId
        ]);
    }

    public function test_withTags_factory_method(): void
    {
        $tags = Tag::factory()->count(3)->create();
        $tagIds = $tags->pluck('id')->toArray();

        $item = Item::factory()->withTags($tagIds)->create();

        $this->assertCount(3, $item->tags);
        foreach ($tags as $tag) {
            $this->assertTrue($item->tags->contains($tag));
        }
    }

    public function test_withTags_factory_method_without_args(): void
    {
        Tag::factory()->count(5)->create();

        $item = Item::factory()->withTags()->create();

        $this->assertCount(2, $item->tags);
    }
}
