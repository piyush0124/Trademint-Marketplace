<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Starting a payment.
 *
 * Note what is *not* accepted: the amount. It is taken from the order's
 * outstanding balance server-side, which removes amount manipulation as a
 * category of attack rather than validating against it.
 *
 * `idempotency_key` is a retry token, not an identity claim — a repeated key
 * returns the original attempt instead of creating a second one.
 */
class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'provider' => ['nullable', 'string', 'max:40'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** Accepts the standard header form too, which is how HTTP clients express it. */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('idempotency_key') && $this->hasHeader('Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }
}
