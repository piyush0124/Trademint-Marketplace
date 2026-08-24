<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Customer opens an enquiry with a business matched to their requirement.
 *
 * Note what is *not* accepted here: customer_id, business_id and
 * requirement_id. The customer is the authenticated user, the requirement comes
 * from the route, and the business is derived from the match — so none of the
 * three can be spoofed by the request body.
 */
class StoreEnquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // The match this enquiry follows from, identified by its own uuid.
            // Ownership of that match is verified against the requirement in
            // the controller/service, not here.
            'match_id' => ['required', 'string', 'exists:requirement_matches,uuid'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
