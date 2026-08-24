<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\QuoteItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a quote.
 *
 * Only *inputs* are accepted. There is deliberately no rule for subtotal,
 * tax_amount or total_amount: those are computed by QuoteCalculator, and any
 * such key in the payload is ignored rather than validated, so a client cannot
 * dictate a price by sending its own totals.
 *
 * Ownership fields (customer_id, business_id, requirement_id) are likewise
 * absent — they come from the enquiry in the route.
 *
 * Every rule here is category-agnostic: the same payload shape describes 500
 * units of steel, a website build or a legal consultation.
 */
class StoreQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            // Restricted to currencies the marketplace declares support for;
            // omitting it falls back to the configured default.
            'currency' => ['nullable', 'string', 'size:3', Rule::in(config('marketplace.supported_currencies', []))],

            // A quote may be valid indefinitely, but a validity date in the past
            // is meaningless.
            'validity_date' => ['nullable', 'date', 'after_or_equal:today'],
            // Services and projects have no delivery; products often have no
            // completion date. Both are optional and independent.
            'estimated_delivery_date' => ['nullable', 'date', 'after_or_equal:today'],
            'estimated_completion_date' => ['nullable', 'date', 'after_or_equal:today'],

            'payment_terms' => ['nullable', 'string', 'max:2000'],
            'warranty_terms' => ['nullable', 'string', 'max:2000'],
            'cancellation_terms' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'delivery_fee' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'other_charges' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            // Quote-level discount only; per-line discounts live on the items.
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],

            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.item_type' => ['required', 'string', Rule::in(array_column(QuoteItemType::cases(), 'value'))],
            // Optional pointer at the business's own catalogue entry. Validated
            // for ownership in QuoteService, and dropped if it does not belong
            // to the quoting business.
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
            'items.required' => 'A quote needs at least one line item.',
            'items.*.quantity.gt' => 'Quantity must be greater than zero.',
            'items.*.unit_price.min' => 'A price cannot be negative.',
            'items.*.tax_rate.max' => 'A tax rate cannot exceed 100%.',
        ];
    }
}
