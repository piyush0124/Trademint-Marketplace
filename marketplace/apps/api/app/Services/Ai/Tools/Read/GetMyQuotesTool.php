<?php

namespace App\Services\Ai\Tools\Read;

use App\Enums\QuoteStatus;
use App\Enums\UserRole;
use App\Models\Quote;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\BaseTool;

/**
 * Quotes the caller has received (customer) or sent (business owner).
 *
 * One tool, two scopes, chosen by the caller's role rather than by an argument. That
 * keeps the model's vocabulary small while making cross-role leakage impossible: an
 * owner asking about "my quotes" can only ever reach the quotes their own business
 * authored.
 *
 * Money is passed through as the stored decimal string and never recomputed here.
 * The AI layer reports figures; it does not do arithmetic on them.
 */
class GetMyQuotesTool extends BaseTool
{
    public function name(): string
    {
        return 'get_my_quotes';
    }

    public function description(): string
    {
        return 'For a customer: quotes received on their requirements. For a business owner: quotes their business has sent. '
            .'Optionally filter by status. Use for questions about pricing, validity and who has quoted.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'status' => $this->stringProperty('Filter by quote status.', array_column(QuoteStatus::cases(), 'value')),
            'requirement_id' => $this->stringProperty('Optional requirement uuid to restrict to one requirement.'),
            'limit' => $this->integerProperty('Maximum quotes to return (default 8).', 1, 20),
        ]);
    }

    public function roles(): array
    {
        return [UserRole::Customer, UserRole::Owner];
    }

    public function handle(User $user, array $arguments): array
    {
        $query = Quote::query()
            ->with(['business:id,name,slug,rating,review_count,verification_status', 'requirement:id,uuid,title'])
            ->latest('id');

        if ($user->isCustomer()) {
            $query->where('customer_id', $user->id);
        } else {
            $business = $user->business;

            if ($business === null) {
                return ['count' => 0, 'quotes' => [], 'note' => 'This user has no business profile yet.'];
            }

            $query->where('business_id', $business->id);
        }

        if (! empty($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }

        if (! empty($arguments['requirement_id'])) {
            // Scoped through the already-restricted query, so naming someone else's
            // requirement uuid simply returns nothing.
            $query->whereHas('requirement', fn ($q) => $q->where('uuid', $arguments['requirement_id']));
        }

        $quotes = $query->limit($this->limit($arguments, 8))->get();

        return [
            'count' => $quotes->count(),
            'quotes' => $quotes->map(fn (Quote $quote): array => [
                'id' => $quote->uuid,
                'quote_number' => $quote->quote_number,
                'status' => $quote->status->value,
                'requirement' => $quote->requirement?->title,
                'requirement_id' => $quote->requirement?->uuid,
                'business' => $quote->business?->name,
                'business_slug' => $quote->business?->slug,
                'business_rating' => $quote->business?->rating !== null ? (float) $quote->business->rating : null,
                'total_amount' => $quote->total_amount,
                'currency' => $quote->currency,
                'validity_date' => $quote->validity_date?->toDateString(),
                'is_expired' => $quote->validity_date !== null && $quote->validity_date->isPast(),
                'estimated_delivery_date' => $quote->estimated_delivery_date?->toDateString(),
                'created_at' => $quote->created_at?->toDateString(),
            ])->all(),
        ];
    }
}
