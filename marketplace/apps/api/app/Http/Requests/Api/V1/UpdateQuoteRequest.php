<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\QuoteItemType;
use Illuminate\Validation\Rule;

/**
 * Editing a draft quote. Same fields as creating one, but every top-level field
 * is optional so a builder can save one section at a time.
 *
 * `items` remains all-or-nothing: if it is present it replaces the entire set,
 * because totals can only be recomputed from a complete list of lines.
 */
class UpdateQuoteRequest extends StoreQuoteRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', Rule::in(config('marketplace.supported_currencies', []))],

            'validity_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'estimated_delivery_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'estimated_completion_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],

            'payment_terms' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'warranty_terms' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'cancellation_terms' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'delivery_fee' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999'],
            'other_charges' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999'],
            'discount_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999'],

            'items' => ['sometimes', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required_with:items', 'string', 'max:500'],
            'items.*.item_type' => ['required_with:items', 'string', Rule::in(array_column(QuoteItemType::cases(), 'value'))],
            'items.*.reference_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0', 'max:9999999'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0', 'max:999999999999'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
