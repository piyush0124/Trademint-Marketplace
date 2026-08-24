<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Requesting a reset link.
 *
 * Note what is *absent*: no `exists:users,email` rule.
 *
 * That rule is the obvious thing to add and it would defeat the entire design.
 * It returns a 422 "selected email is invalid" for an unregistered address, which
 * is precisely the account-enumeration signal PasswordResetController goes out of
 * its way to withhold. Validation here only checks that the input is a
 * well-formed email; whether an account exists is deliberately not this layer's
 * business.
 */
class ForgotPasswordRequest extends FormRequest
{
    /** Public by definition — the caller cannot sign in. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            // Present only on the verify-token call, which reuses this request.
            'token' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
