<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

abstract class BatchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the base validation rules that apply to the request.
     *
     * @return array<string, array<ValidationRule|string>>
     */
    protected function baseRules(): array
    {
        return [
            'item_id' => ['string', 'uuid', 'exists:items,id'],
            'location_id' => ['uuid', 'exists:locations,id'],
            'expires_at' => ['date', 'after_or_equal:today'],
            'quantity' => ['integer'],
        ];
    }

    /**
     * Get the base custom messages for validator errors.
     *
     * @return array<string, string>
     */
    protected function baseMessages(): array
    {
        return [
            'location_id.exists' => __('The selected location does not exist.'),
            'item_id.exists' => __('The selected item does not exist.'),
            'expires_at.after_or_equal' => __('The expiration date must be today or in the future.'),
            'quantity.integer' => __('The quantity must be a whole number.'),
        ];
    }
}
