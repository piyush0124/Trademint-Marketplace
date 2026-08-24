<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accept / reject / withdraw payload. `revision_id` is optional here but, when
 * supplied, is checked against the live offer — so a client that has been
 * looking at a stale screen gets a 409 instead of accepting terms it never saw.
 */
class RespondToNegotiationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'revision_id' => ['nullable', 'string', 'exists:quote_revisions,uuid'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
