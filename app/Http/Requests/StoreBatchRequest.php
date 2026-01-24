<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBatchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'string', 'uuid', 'exists:items,id'],
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
            'expires_at' => ['required', 'date', 'after_or_equal:today'],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'location_id.required' => 'A location must be selected.',
            'location_id.exists' => 'The selected location does not exist.',
            'item_id.required' => 'An item must be selected.',
            'item_id.exists' => 'The selected item does not exist.',
            'expires_at.required' => 'The expiration date is required.',
            'expires_at.after_or_equal' => 'The expiration date must be today or in the future.',
            'quantity.required' => 'The quantity is required.',
            'quantity.integer' => 'The quantity must be a whole number.',
            'quantity.min' => 'The quantity must be at least 1.',
        ];
    }
}
