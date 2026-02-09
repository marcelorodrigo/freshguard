<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class UpdateBatchRequest extends BatchRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(
            $this->baseRules(),
            [
                'item_id' => ['sometimes', ...$this->baseRules()['item_id']],
                'location_id' => ['sometimes', ...$this->baseRules()['location_id']],
                'expires_at' => ['sometimes', ...$this->baseRules()['expires_at']],
                'quantity' => ['sometimes', 'min:0', ...$this->baseRules()['quantity']],
            ]
        );
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(
            $this->baseMessages(),
            [
                'quantity.min' => __('The quantity cannot be negative.'),
            ]
        );
    }
}
