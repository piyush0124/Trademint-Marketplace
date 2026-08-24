<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Completing a reset.
 *
 * The password rule matches registration (minimum 8) rather than imposing a
 * stricter policy at reset time. A reset screen that demands more than signup did
 * blocks a user who is already locked out and cannot understand why the password
 * they are choosing is being refused — and it can leave them unable to re-enter
 * the password they actually wanted.
 *
 * `confirmed` requires a `password_confirmation` field. Retained even though the
 * SPA shows a reveal toggle, because a typo here is unrecoverable without another
 * email round trip.
 */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required' => 'This reset link is incomplete. Please use the link from your email, or request a new one.',
            'password.confirmed' => 'Both passwords must match.',
        ];
    }
}
