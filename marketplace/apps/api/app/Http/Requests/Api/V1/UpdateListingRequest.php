<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:30'],
            'min_order_quantity' => ['nullable', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(config('marketplace.supported_currencies'))],
            'location' => ['nullable', 'array'],
            'location.city' => ['required_with:location', 'string', 'max:100'],
            'location.area' => ['nullable', 'string', 'max:100'],
            'location.locality' => ['nullable', 'string', 'max:100'],
            'location.state' => ['nullable', 'string', 'max:100'],
            'location.country' => ['nullable', 'string', 'size:2'],
            'location.postal_code' => ['nullable', 'string', 'max:20'],
            'location.latitude' => ['nullable', 'numeric'],
            'location.longitude' => ['nullable', 'numeric'],
            'attributes' => ['nullable', 'array'],
        ];
    }
}
