<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SyncBusinessIndustriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() ?? false;
    }

    public function rules(): array
    {
        return [
            'industry_ids' => ['present', 'array', 'max:50'],
            'industry_ids.*' => ['integer', 'exists:industries,id'],
        ];
    }
}
