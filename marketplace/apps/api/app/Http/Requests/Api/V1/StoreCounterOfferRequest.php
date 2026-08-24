<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\QuoteItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A counter offer, from either side.
 *
 * Same payload shape as a quote — items plus charges plus terms — because a
 * counter *is* a restated offer: "400 units instead of 500, at ₹780, delivered
 * two weeks later". One shape, one calculator, one set of rules, whichever party
 * is making the move.
 *
 * As everywhere else in the money path, there is no rule for subtotal, tax or
 * total: those are computed server-side and any such key in the body is ignored.
 * `revision_id` is the optimistic-concurrency token, not an identity claim.
 */
class StoreCounterOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // The offer being responded to. Required so two people countering at
            // once cannot both apply their change to the same revision.
            'revision_id' => ['required', 'string', 'exists:quote_revisions,uuid'],
            'message' => ['nullable', 'string', 'max:2000'],
            'is_final_offer' => ['nullable', 'boolean'],

            'validity_date' => ['nullable', 'date', 'after_or_equal:today'],
            'estimated_delivery_date' => ['nullable', 'date', 'after_or_equal:today'],
            'estimated_completion_date' => ['nullable', 'date', 'after_or_equal:today'],

            'payment_terms' => ['nullable', 'string', 'max:2000'],
            'warranty_terms' => ['nullable', 'string', 'max:2000'],
            'cancellation_terms' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'delivery_fee' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'other_charges' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],

            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.item_type' => ['required', 'string', Rule::in(array_column(QuoteItemType::cases(), 'value'))],
            'items.*.reference_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'revision_id.required' => 'Reload the negotiation — a counter offer must respond to a specific offer.',
            'items.required' => 'A counter offer needs at least one line item.',
            'items.*.unit_price.min' => 'A price cannot be negative.',
        ];
    }
}
