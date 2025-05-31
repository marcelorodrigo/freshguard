<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\ItemRequest;
use App\Models\Location;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

class ItemRequestTest extends TestCase
{
    use RefreshDatabase;

    protected Location $location;
    protected Tag $tag;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a location and tag for testing
        $this->location = Location::factory()->create();
        $this->tag = Tag::factory()->create();
    }

    public function test_validation_passes_with_valid_data(): void
    {
        $request = new ItemRequest();

        $data = [
            'location_id' => $this->location->id,
            'name' => 'Test Item',
            'description' => 'Test Description',
            'tags' => [$this->tag->id],
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($request->authorize());
        $this->assertFalse($validator->fails());
    }

    public function test_validation_passes_with_emojis(): void
    {
        $request = new ItemRequest();

        $data = [
            'location_id' => $this->location->id,
            'name' => '📦',
            'description' => 'Special item 😊',
            'tags' => [$this->tag->id],
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($request->authorize());
        $this->assertFalse($validator->fails());
    }

    public function test_validation_passes_with_null_description(): void
    {
        $request = new ItemRequest();

        $data = [
            'location_id' => $this->location->id,
            'name' => 'Test Item',
            'description' => null,
            'tags' => [$this->tag->id],
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($request->authorize());
        $this->assertFalse($validator->fails());
    }

    public function test_validation_passes_without_tags(): void
    {
        $request = new ItemRequest();

        $data = [
            'location_id' => $this->location->id,
            'name' => 'Test Item',
            'description' => 'Test Description',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($request->authorize());
        $this->assertFalse($validator->fails());
    }

    public function test_validation_fails_without_location_id(): void
    {
        $request = new ItemRequest();

        $data = [
            'name' => 'Test Item',
            'description' => 'Test Description',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('location_id', $validator->errors()->toArray());
    }

    public function test_validation_fails_with_invalid_location_id(): void
    {
        $request = new ItemRequest();

        $data = [
            'location_id' => 'not-a-uuid',
            'name' => 'Test Item',
            'description' => 'Test Description',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('location_id', $validator->errors()->toArray());
    }

    public function test_validation_fails_with_nonexistent_location_id(): void
    {
        $request = new ItemRequest();

        $data = [
            'location_id' => Str::uuid()->toString(),
            'name' => 'Test Item',
            'description' => 'Test Description',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('location_id', $validator->errors()->toArray());
    }

    public function test_validation_fails_without_name(): void
    {
        $request = new ItemRequest();

        $data = [
            'location_id' => $this->location->id,
            'description' => 'Test Description',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    public function test_validation_fails_with_name_too_long(): void
    {
        $request = new ItemRequest();

        $data = [
            'location_id' => $this->location->id,
            'name' => Str::repeat('a', 256), // 256 characters exceeds the 255 max
            'description' => 'Test Description',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    public function test_validation_fails_with_description_too_long(): void
    {
        $request = new ItemRequest();

        $data = [
            'location_id' => $this->location->id,
            'name' => 'Test Item',
            'description' => Str::repeat('a', 256), // 256 characters exceeds the 255 max
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('description', $validator->errors()->toArray());
    }

    public function test_validation_fails_with_invalid_tags_format(): void
    {
        $request = new ItemRequest();

        $data = [
            'location_id' => $this->location->id,
            'name' => 'Test Item',
            'description' => 'Test Description',
            'tags' => 'not-an-array',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('tags', $validator->errors()->toArray());
    }

    public function test_validation_fails_with_invalid_tag_id(): void
    {
        $request = new ItemRequest();

        $data = [
            'location_id' => $this->location->id,
            'name' => 'Test Item',
            'description' => 'Test Description',
            'tags' => ['not-a-uuid'],
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('tags.0', $validator->errors()->toArray());
    }

    public function test_validation_fails_with_nonexistent_tag_id(): void
    {
        $request = new ItemRequest();

        $data = [
            'location_id' => $this->location->id,
            'name' => 'Test Item',
            'description' => 'Test Description',
            'tags' => [Str::uuid()->toString()],
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('tags.0', $validator->errors()->toArray());
    }

    public function test_validation_passes_with_expiration_notify_days(): void
    {
        $request = new ItemRequest();

        $data = [
            'location_id' => $this->location->id,
            'name' => 'Test Item',
            'description' => 'Test Description',
            'expiration_notify_days' => 5,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($request->authorize());
        $this->assertFalse($validator->fails());
    }

    public function test_validation_passes_with_expiration_notify_days_zero(): void
    {
        $request = new ItemRequest();

        $data = [
            'location_id' => $this->location->id,
            'name' => 'Test Item',
            'description' => 'Test Description',
            'expiration_notify_days' => 0,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($request->authorize());
        $this->assertFalse($validator->fails());
    }

    public function test_validation_fails_with_negative_expiration_notify_days(): void
    {
        $request = new ItemRequest();

        $data = [
            'location_id' => $this->location->id,
            'name' => 'Test Item',
            'description' => 'Test Description',
            'expiration_notify_days' => -1,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('expiration_notify_days', $validator->errors()->toArray());
    }

    public function test_validation_fails_with_non_integer_expiration_notify_days(): void
    {
        $request = new ItemRequest();

        $data = [
            'location_id' => $this->location->id,
            'name' => 'Test Item',
            'description' => 'Test Description',
            'expiration_notify_days' => 'not-a-number',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('expiration_notify_days', $validator->errors()->toArray());
    }
}
