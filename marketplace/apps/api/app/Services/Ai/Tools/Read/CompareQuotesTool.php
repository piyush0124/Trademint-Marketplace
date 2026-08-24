<?php

namespace App\Services\Ai\Tools\Read;

use App\Enums\UserRole;
use App\Models\Requirement;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\BaseTool;

/**
 * Side-by-side quote data for one of the caller's own requirements.
 *
 * Every figure is read from the stored quote. The AI does not compute a total, does
 * not convert a currency, and does not rank the options — its job downstream is to
 * describe the trade-offs neutrally so the buyer decides. A "cheapest" flag is
 * computed here, server-side, because that is a fact about the data rather than a
 * judgement, and letting the model work it out by comparing strings is how you get
 * confidently wrong arithmetic.
 */
class CompareQuotesTool extends BaseTool
{
    public function name(): string
    {
        return 'compare_quotes';
    }

    public function description(): string
    {
        return 'Get all quotes on one of the signed-in customer\'s requirements, with totals, delivery dates, validity, '
            .'terms and supplier trust signals, ready to compare. Use before discussing which quote suits them best.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'requirement_id' => $this->stringProperty('The requirement uuid whose quotes should be compared.'),
        ], ['requirement_id']);
    }

    public function roles(): array
    {
        return [UserRole::Customer];
    }

    /** Ownership check on the specific requirement — this is the IDOR gate. */
    public function authorize(User $user, array $arguments): bool
    {
        return Requirement::query()
            ->where('uuid', (string) $arguments['requirement_id'])
            ->where('customer_id', $user->id)
            ->exists();
    }

    public function handle(User $user, array $arguments): array
    {
        $requirement = Requirement::query()
            ->where('uuid', (string) $arguments['requirement_id'])
            ->where('customer_id', $user->id)
            ->with([
                'quotes' => fn ($q) => $q->whereIn('status', ['sent', 'viewed', 'accepted'])->latest('id'),
                'quotes.business:id,name,slug,rating,review_count,verification_status,years_in_business',
                'quotes.items',
            ])
            ->first();

        if ($requirement === null) {
            return ['found' => false, 'note' => 'No such requirement.'];
        }

        $quotes = $requirement->quotes;

        if ($quotes->isEmpty()) {
            return [
                'found' => true,
                'requirement' => $requirement->title,
                'count' => 0,
                'quotes' => [],
                'note' => 'No quotes have been received yet. Say so; do not invent comparisons.',
            ];
        }

        // Computed here rather than left to the model: comparing money is arithmetic,
        // and arithmetic is not what a language model is for.
        $lowestTotal = $quotes->min(fn ($quote) => (float) $quote->total_amount);

        return [
            'found' => true,
            'requirement' => $requirement->title,
            'count' => $quotes->count(),
            'currency' => $quotes->first()->currency,
            'lowest_total' => (string) $lowestTotal,
            'quotes' => $quotes->map(fn ($quote): array => [
                'id' => $quote->uuid,
                'quote_number' => $quote->quote_number,
                'status' => $quote->status->value,
                'supplier' => $quote->business?->name,
                'supplier_slug' => $quote->business?->slug,
                'supplier_verified' => $quote->business?->verificationStatus()->value === 'verified',
                'supplier_rating' => $quote->business?->rating !== null ? (float) $quote->business->rating : null,
                'supplier_review_count' => (int) ($quote->business?->review_count ?? 0),
                'supplier_years_in_business' => $quote->business?->years_in_business,
                'subtotal' => $quote->subtotal,
                'tax_amount' => $quote->tax_amount,
                'delivery_fee' => $quote->delivery_fee,
                'discount_amount' => $quote->discount_amount,
                'total_amount' => $quote->total_amount,
                'is_lowest_total' => (float) $quote->total_amount === $lowestTotal,
                'line_items' => $quote->items->count(),
                'estimated_delivery_date' => $quote->estimated_delivery_date?->toDateString(),
                'estimated_completion_date' => $quote->estimated_completion_date?->toDateString(),
                'validity_date' => $quote->validity_date?->toDateString(),
                'is_expired' => $quote->validity_date !== null && $quote->validity_date->isPast(),
                'payment_terms' => $quote->payment_terms,
                'warranty_terms' => $quote->warranty_terms,
                'cancellation_terms' => $quote->cancellation_terms,
            ])->all(),
            'note' => 'Present trade-offs neutrally and let the customer decide. Never recommend one quote as "the best". '
                .'Cheapest is already flagged; do not recompute any figure.',
        ];
    }
}
