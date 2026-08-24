<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SyncBusinessServiceAreasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() ?? false;
    }

    public function rules(): array
    {
        return [
            'locations' => ['present', 'array', 'max:100'],
            'locations.*.city' => ['required', 'string', 'max:100'],
            'locations.*.state' => ['nullable', 'string', 'max:100'],
            'locations.*.country' => ['nullable', 'string', 'size:2'],
        ];
    }
}
