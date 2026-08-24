<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates a DRAFT requirement. Deliberately permissive — a customer can
 * start a draft with just a category and fill everything else in over the
 * multi-step form (describe -> quantity -> budget -> location -> date).
 * RequirementService::publish() enforces completeness later.
 */
class StoreRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:30'],
            'budget_min' => ['nullable', 'numeric', 'min:0'],
            'budget_max' => ['nullable', 'numeric', 'min:0', 'gte:budget_min'],
            'required_date' => ['nullable', 'date', 'after_or_equal:today'],
            'urgency' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
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
