<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\LocationRequest;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocationRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_passes_with_valid_data(): void
    {
        $request = new LocationRequest();

        $data = [
            'name' => 'Test Location',
            'description' => 'Test Description',
            'parent_id' => null,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($request->authorize());
        $this->assertFalse($validator->fails());
    }

    public function test_validation_passes_with_emojis(): void
    {
        $request = new LocationRequest();

        $data = [
            'name' => '🏠',
            'description' => 'Nice place to relax 😊',
            'parent_id' => null,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($request->authorize());
        $this->assertFalse($validator->fails());
    }

    public function test_validation_passes_with_null_description(): void
    {
        $request = new LocationRequest();

        $data = [
            'name' => 'Test Location',
            'description' => null,
            'parent_id' => null,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($request->authorize());
        $this->assertFalse($validator->fails());
    }

    public function test_validation_passes_with_valid_parent_id(): void
    {
        // Create a parent location
        $parent = Location::create([
            'name' => 'Parent Location',
            'description' => 'Parent Description',
        ]);

        $request = new LocationRequest();

        $data = [
            'name' => 'Child Location',
            'description' => 'Child Description',
            'parent_id' => $parent->id,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($request->authorize());
        $this->assertFalse($validator->fails());
    }

    public function test_validation_fails_without_name(): void
    {
        $request = new LocationRequest();

        $data = [
            'description' => 'Test Description',
            'parent_id' => null,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    public function test_validation_fails_with_name_too_long(): void
    {
        $request = new LocationRequest();

        $data = [
            'name' => Str::repeat('a', 256), // 256 characters exceeds the 255 max
            'description' => 'Test Description',
            'parent_id' => null,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    public function test_validation_fails_with_description_too_long(): void
    {
        $request = new LocationRequest();

        $data = [
            'name' => 'Test Location',
            'description' => Str::repeat('a', 256), // 256 characters exceeds the 255 max
            'parent_id' => null,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('description', $validator->errors()->toArray());
    }

    public function test_validation_fails_with_invalid_parent_id(): void
    {
        $request = new LocationRequest();

        $data = [
            'name' => 'Test Location',
            'description' => 'Test Description',
            'parent_id' => Str::uuid(), // Non-existent UUID
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('parent_id', $validator->errors()->toArray());
    }
}
