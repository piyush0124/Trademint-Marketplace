<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving an order to a new status.
 *
 * The request may name a target status, but nothing else: not the party acting
 * (derived from the authenticated user), not the timestamps, not the money. Whether
 * this particular party may set this particular status from the current one is
 * OrderService's decision.
 */
class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
