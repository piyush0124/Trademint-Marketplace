<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() ?? false;
    }

    public function rules(): array
    {
        return [
            'category_slug' => ['nullable', 'string', Rule::exists('categories', 'slug')->where('is_active', true)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'min_order' => ['nullable', 'integer', 'min:1'],
            'estimated_delivery_time' => ['nullable', 'string', 'max:60'],
            'customization_available' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
