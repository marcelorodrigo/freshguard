<?php

declare(strict_types=1);

namespace App\Http\Requests;

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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
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
            'item_id.exists' => 'The selected item does not exist.',
            'expires_at.after_or_equal' => 'The expiration date must be today or in the future.',
            'quantity.integer' => 'The quantity must be a whole number.',
            'quantity.min' => 'The quantity cannot be negative.',
        ];
    }
}
