<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\TagRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

class TagRequestTest extends TestCase
{

    public function test_validation_passes_with_valid_data(): void
    {
        $request = new TagRequest();

        $data = [
            'name' => 'Test Tag',
            'description' => 'Test Description',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($request->authorize());
        $this->assertFalse($validator->fails());
    }

    public function test_validation_passes_with_emojis(): void
    {
        $request = new TagRequest();

        $data = [
            'name' => '🏷️',
            'description' => 'Special tag 😊',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($request->authorize());
        $this->assertFalse($validator->fails());
    }

    public function test_validation_passes_with_null_description(): void
    {
        $request = new TagRequest();

        $data = [
            'name' => 'Test Tag',
            'description' => null,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($request->authorize());
        $this->assertFalse($validator->fails());
    }

    public function test_validation_fails_without_name(): void
    {
        $request = new TagRequest();

        $data = [
            'description' => 'Test Description',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    public function test_validation_fails_with_name_too_long(): void
    {
        $request = new TagRequest();

        $data = [
            'name' => Str::repeat('a', 256), // 256 characters exceeds the 255 max
            'description' => 'Test Description',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }
}
