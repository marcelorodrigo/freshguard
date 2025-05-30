<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LocationRequest extends FormRequest
{
    /**
     * Allows all users to make this location request.
     *
     * Always returns true, granting authorization for any user.
     *
     * @return bool True, indicating authorization is granted.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Returns the validation rules for location-related request data.
     *
     * Validates that the `name` field is required and no longer than 255 characters, the `description` field is optional and up to 255 characters, and the `parent_id` field is optional but must reference an existing location ID if provided.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string> Validation rules for the request.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'max:255'],
            'description' => ['nullable', 'max:255'],
            'parent_id' => ['nullable', 'exists:locations,id']
        ];
    }
}
