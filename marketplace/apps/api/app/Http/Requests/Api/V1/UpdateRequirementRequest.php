<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'quantity' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:30'],
            'budget_min' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'budget_max' => ['sometimes', 'nullable', 'numeric', 'min:0', 'gte:budget_min'],
            'required_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'urgency' => ['sometimes', 'nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'location' => ['nullable', 'array'],
            'location.city' => ['required_with:location', 'string', 'max:100'],
            'location.area' => ['nullable', 'string', 'max:100'],
            'location.locality' => ['nullable', 'string', 'max:100'],
            'location.state' => ['nullable', 'string', 'max:100'],
            'location.country' => ['nullable', 'string', 'size:2'],
            'location.postal_code' => ['nullable', 'string', 'max:20'],
            'location.latitude' => ['nullable', 'numeric'],
            'location.longitude' => ['nullable', 'numeric'],
        ];
    }
}
