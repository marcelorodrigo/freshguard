<?php

namespace Tests\Unit\Models;

use App\Models\Batch;
use App\Models\Item;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_uuids_as_primary_key()
    {
        $batch = Batch::factory()->create();
        $this->assertIsString($batch->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $batch->id);
    }

    public function test_it_belongs_to_an_item()
    {
        $batch = Batch::factory()->create();

        $this->assertInstanceOf(BelongsTo::class, $batch->item());
        $this->assertInstanceOf(Item::class, $batch->item);
    }

    public function test_it_has_correct_fillable_attributes()
    {
        $expected = [
            'item_id',
            'expires_at',
            'quantity',
        ];

        $this->assertEquals($expected, new Batch()->getFillable());
    }

    public function test_it_casts_attributes_correctly()
    {
        $batch = Batch::factory()->create();

        $this->assertIsString($batch->item_id);
        $this->assertInstanceOf(Carbon::class, $batch->expires_at);
        $this->assertIsInt($batch->quantity);
    }

    public function test_it_creates_valid_factory_instances()
    {
        $batch = Batch::factory()->create();

        $this->assertDatabaseHas('batches', [
            'id' => $batch->id,
        ]);

        $this->assertNotNull($batch->item_id);
        $this->assertNotNull($batch->expires_at);
        $this->assertNotNull($batch->quantity);
    }

    public function test_it_can_create_batches_with_custom_attributes()
    {
        $item = Item::factory()->create();
        $expiresAt = now()->addDays(30);

        $batch = Batch::factory()->create([
            'item_id' => $item->id,
            'expires_at' => $expiresAt,
            'quantity' => 42,
        ]);

        $this->assertEquals($item->id, $batch->item_id);
        $this->assertEquals($expiresAt->toDateTimeString(), $batch->expires_at->toDateTimeString());
        $this->assertEquals(42, $batch->quantity);

        $item->refresh();
        $this->assertEquals(42, $item->quantity);
    }

    public function test_it_can_find_batches_by_item()
    {
        // Create item with multiple batches
        $item = Item::factory()->create();
        Batch::factory()->count(3)->create([
            'item_id' => $item->id,
        ]);

        // Create another item with batches to ensure filtering works
        $anotherItem = Item::factory()->create();
        Batch::factory()->count(2)->create([
            'item_id' => $anotherItem->id,
        ]);

        // Test relationship
        $this->assertCount(3, $item->batches);
        $this->assertCount(2, $anotherItem->batches);
    }

    public function test_deleting_batch_updates_item_quantity()
    {
        // Create an item
        $item = Item::factory()->create();

        // Create three batches with specific quantities
        $batch10 = Batch::factory()->create([
            'item_id' => $item->id,
            'quantity' => 10
        ]);

        $batch20 = Batch::factory()->create([
            'item_id' => $item->id,
            'quantity' => 20
        ]);

        $batch30 = Batch::factory()->create([
            'item_id' => $item->id,
            'quantity' => 30
        ]);

        // Refresh the item to get updated quantity
        $item->refresh();

        // Verify total quantity is the sum of all three batches
        $this->assertEquals(60, $item->quantity);

        // Delete the batch with quantity 20
        $batch20->delete();

        // Refresh the item to get updated quantity
        $item->refresh();

        // Verify item quantity is now 40 (10 + 30)
        $this->assertEquals(40, $item->quantity);
    }
}
