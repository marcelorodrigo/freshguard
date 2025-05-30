<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\UpdateBatchRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;
use App\Models\Item;

class UpdateBatchRequestTest extends TestCase
{
    use RefreshDatabase;

    private UpdateBatchRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new UpdateBatchRequest();
    }

    public function test_authorization_always_returns_true()
    {
        $this->assertTrue($this->request->authorize());
    }

    public function test_rules_exist_for_fields()
    {
        $rules = $this->request->rules();

        $this->assertArrayHasKey('item_id', $rules);
        $this->assertArrayHasKey('expires_at', $rules);
        $this->assertArrayHasKey('quantity', $rules);
    }

    public function test_item_id_validation_rules()
    {
        $rules = $this->request->rules();

        $this->assertContains('sometimes', $rules['item_id']);
        $this->assertContains('string', $rules['item_id']);
        $this->assertContains('uuid', $rules['item_id']);
        $this->assertContains('exists:items,id', $rules['item_id']);
    }

    public function test_expires_at_validation_rules()
    {
        $rules = $this->request->rules();

        $this->assertContains('sometimes', $rules['expires_at']);
        $this->assertContains('date', $rules['expires_at']);
        $this->assertContains('after_or_equal:today', $rules['expires_at']);
    }

    public function test_quantity_validation_rules()
    {
        $rules = $this->request->rules();

        $this->assertContains('sometimes', $rules['quantity']);
        $this->assertContains('integer', $rules['quantity']);
        $this->assertContains('min:0', $rules['quantity']);
    }

    public function test_custom_messages_are_defined()
    {
        $messages = $this->request->messages();

        $this->assertIsArray($messages);
        $this->assertArrayHasKey('item_id.exists', $messages);
        $this->assertArrayHasKey('expires_at.after_or_equal', $messages);
        $this->assertArrayHasKey('quantity.integer', $messages);
        $this->assertArrayHasKey('quantity.min', $messages);
    }

    public function test_valid_data_passes_validation()
    {
        $item = Item::factory()->create();

        // Test with all fields
        $data = [
            'item_id' => $item->id,
            'expires_at' => now()->addDays(1)->format('Y-m-d'),
            'quantity' => 10,
        ];

        $validator = Validator::make($data, $this->request->rules());
        $this->assertFalse($validator->fails());

        // Test with partial data (only updating quantity)
        $partialData = [
            'quantity' => 5,
        ];

        $validator = Validator::make($partialData, $this->request->rules());
        $this->assertFalse($validator->fails());
    }

    public function test_invalid_data_fails_validation()
    {
        $data = [
            'item_id' => 'not-a-uuid',
            'expires_at' => now()->subDays(1)->format('Y-m-d'),  // Past date
            'quantity' => -1,  // Below minimum
        ];

        $validator = Validator::make($data, $this->request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('item_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('expires_at', $validator->errors()->toArray());
        $this->assertArrayHasKey('quantity', $validator->errors()->toArray());
    }
}
