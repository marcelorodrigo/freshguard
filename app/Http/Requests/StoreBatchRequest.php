<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class StoreBatchRequest extends BatchRequest
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
                'item_id' => ['required', ...$this->baseRules()['item_id']],
                'location_id' => ['required', ...$this->baseRules()['location_id']],
                'expires_at' => ['required', ...$this->baseRules()['expires_at']],
                'quantity' => ['required', 'min:1', ...$this->baseRules()['quantity']],
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
                'location_id.required' => __('A location must be selected.'),
                'item_id.required' => __('An item must be selected.'),
                'expires_at.required' => __('The expiration date is required.'),
                'quantity.required' => __('The quantity is required.'),
                'quantity.min' => __('The quantity must be at least 1.'),
            ]
        );
    }
}
