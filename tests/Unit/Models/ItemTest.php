<?php

namespace Tests\Unit\Models;

use App\Models\Item;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
            'quantity',
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
            'quantity' => 0, // Default quantity
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

    public function test_has_batches_relationship(): void
    {
        $item = new Item();

        $this->assertInstanceOf(HasMany::class, $item->batches());
        $this->assertInstanceOf(Collection::class, $item->batches);
    }

    public function test_can_have_many_batches(): void
    {
        $item = Item::factory()->create();
        $batches = Batch::factory()->count(3)->for($item)->create();

        $this->assertCount(3, $item->batches);
        foreach ($batches as $batch) {
            $this->assertTrue($item->batches->contains($batch));
        }
    }

    public function test_scope_with_batches_expiring_within_days_includes_items_with_batches_expiring_in_range(): void
    {
        // Set fixed date for testing
        Carbon::setTestNow('2025-06-11 17:00:00');

        // Create items with batches expiring at different times
        $itemWithBatchExpiringToday = Item::factory()->create();
        $itemWithBatchExpiringIn3Days = Item::factory()->create();
        $itemWithBatchExpiringIn7Days = Item::factory()->create();
        $itemWithBatchExpiringIn10Days = Item::factory()->create();
        $itemWithExpiredBatch = Item::factory()->create();
        $itemWithNoExpiringBatchInRange = Item::factory()->create();

        // Create batches with specific expiration dates
        Batch::factory()->for($itemWithBatchExpiringToday)->create(['expires_at' => Carbon::now()]);
        Batch::factory()->for($itemWithBatchExpiringIn3Days)->create(['expires_at' => Carbon::now()->addDays(3)]);
        Batch::factory()->for($itemWithBatchExpiringIn7Days)->create(['expires_at' => Carbon::now()->addDays(7)]);
        Batch::factory()->for($itemWithBatchExpiringIn10Days)->create(['expires_at' => Carbon::now()->addDays(10)]);
        Batch::factory()->for($itemWithExpiredBatch)->create(['expires_at' => Carbon::now()->subDays(1)]);
        Batch::factory()->for($itemWithNoExpiringBatchInRange)->create(['expires_at' => Carbon::now()->addDays(15)]);

        // Test scope with 5 days range
        $itemsExpiringWithin5Days = Item::withBatchesExpiringWithinDays(5)->get();

        // Should include items with batches expiring today, in 3 days, but not those expiring in 7 or 10 days
        $this->assertTrue($itemsExpiringWithin5Days->contains($itemWithBatchExpiringToday));
        $this->assertTrue($itemsExpiringWithin5Days->contains($itemWithBatchExpiringIn3Days));
        $this->assertFalse($itemsExpiringWithin5Days->contains($itemWithBatchExpiringIn7Days));
        $this->assertFalse($itemsExpiringWithin5Days->contains($itemWithBatchExpiringIn10Days));
        $this->assertFalse($itemsExpiringWithin5Days->contains($itemWithExpiredBatch));
        $this->assertFalse($itemsExpiringWithin5Days->contains($itemWithNoExpiringBatchInRange));
    }

    public function test_scope_with_batches_expiring_within_days_works_with_multiple_batches(): void
    {
        // Set fixed date for testing
        Carbon::setTestNow('2025-06-11 18:00:00');

        // Create item with multiple batches
        $item = Item::factory()->create();

        // One batch expires in range, one doesn't
        Batch::factory()->for($item)->create(['expires_at' => Carbon::now()->addDays(3)]);
        Batch::factory()->for($item)->create(['expires_at' => Carbon::now()->addDays(10)]);

        // Item should be included in results because at least one batch expires within range
        $itemsExpiringWithin5Days = Item::withBatchesExpiringWithinDays(5)->get();

        $this->assertTrue($itemsExpiringWithin5Days->contains($item));
    }

    public function test_scope_with_batches_expiring_within_days_returns_empty_collection_when_no_matches(): void
    {
        // Set fixed date for testing
        Carbon::setTestNow('2025-05-30 12:00:00');

        // Create items with batches that don't expire within the range
        $item1 = Item::factory()->create();
        $item2 = Item::factory()->create();

        Batch::factory()->for($item1)->create(['expires_at' => Carbon::now()->addDays(10)]);
        Batch::factory()->for($item2)->create(['expires_at' => Carbon::now()->subDays(1)]);

        // Test scope with 5 days range
        $itemsExpiringWithin5Days = Item::withBatchesExpiringWithinDays(5)->get();

        $this->assertCount(0, $itemsExpiringWithin5Days);
    }

    public function test_scope_with_batches_expiring_within_days_excludes_items_without_batches(): void
    {
        // Create item without batches
        $itemWithoutBatches = Item::factory()->create();

        // Test scope with any days range
        $itemsExpiringWithinDays = Item::withBatchesExpiringWithinDays(30)->get();

        $this->assertFalse($itemsExpiringWithinDays->contains($itemWithoutBatches));
    }
}
