<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBatchRequest extends FormRequest
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
            'location_id' => ['sometimes', 'uuid', 'exists:locations,id'],
            'item_id' => ['sometimes', 'string', 'uuid', 'exists:items,id'],
            'expires_at' => ['sometimes', 'date', 'after_or_equal:today'],
            'quantity' => ['sometimes', 'integer', 'min:0'],
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
            'location_id.exists' => __('The selected location does not exist.'),
            'item_id.exists' => __('The selected item does not exist.'),
            'expires_at.after_or_equal' => __('The expiration date must be today or in the future.'),
            'quantity.integer' => __('The quantity must be a whole number.'),
            'quantity.min' => __('The quantity cannot be negative.'),
        ];
    }
}
