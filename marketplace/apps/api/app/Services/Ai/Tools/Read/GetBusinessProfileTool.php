<?php

namespace App\Services\Ai\Tools\Read;

use App\Enums\UserRole;
use App\Models\Business;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\BaseTool;
use App\Services\Trust\TrustScoreCalculator;

/**
 * One supplier's public profile, including the trust breakdown.
 *
 * Returns exactly what the public passport endpoint publishes — no more. `gst_number`,
 * `email` and the document files are all reachable from the Business model and all
 * deliberately absent here: the AI has no business relaying a government ID to a
 * provider to answer "is this supplier any good".
 *
 * Trust factors come from the existing TrustScoreCalculator, so the score the
 * assistant quotes is the same number the profile page shows.
 */
class GetBusinessProfileTool extends BaseTool
{
    public function __construct(private readonly TrustScoreCalculator $trust) {}

    public function name(): string
    {
        return 'get_business_profile';
    }

    public function description(): string
    {
        return 'Get the public profile of one supplier by slug: category, location, service areas, verification, rating, '
            .'completed orders and the trust score breakdown. Use after search_businesses to explain why a supplier is credible.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'slug' => $this->stringProperty('The supplier slug, as returned by search_businesses.'),
        ], ['slug']);
    }

    public function roles(): array
    {
        return [UserRole::Customer, UserRole::Owner, UserRole::Admin];
    }

    public function handle(User $user, array $arguments): array
    {
        $business = Business::query()
            ->where('slug', (string) $arguments['slug'])
            ->with(['location:id,city,state', 'category:id,name', 'industries:id,name', 'serviceAreas:id,city'])
            ->first();

        if ($business === null) {
            return [
                'found' => false,
                // Explicit instruction, because a model handed an empty result will
                // otherwise fill the gap from training data.
                'note' => 'No supplier exists with that slug. Say so; do not describe a business.',
            ];
        }

        $trust = $this->trust->calculate($business);

        return [
            'found' => true,
            'slug' => $business->slug,
            'name' => $business->name,
            'about' => $business->about,
            'category' => $business->category?->name,
            'industries' => $business->industries->pluck('name')->all(),
            'city' => $business->location?->city,
            'state' => $business->location?->state,
            'service_areas' => $business->serviceAreas->pluck('city')->all(),
            'verification_status' => $business->verificationStatus()->value,
            'is_suspended' => $business->isSuspended(),
            'rating' => $business->rating !== null ? (float) $business->rating : null,
            'review_count' => (int) $business->review_count,
            'years_in_business' => $business->years_in_business,
            'trade_type' => $business->trade_type?->value,
            'accepting_work' => (bool) $business->is_available,
            // Null when there is not enough evidence to score at all — the model is
            // told to report that as "unproven", never as a low score.
            'trust_score' => $trust['score'],
            'trust_factors' => collect($trust['factors'])
                ->map(fn (array $factor): array => [
                    'label' => $factor['label'],
                    'available' => $factor['available'],
                    'evidence' => $factor['evidence'],
                ])->all(),
            'note' => 'A null rating or trust score means not enough data yet, not a poor score.',
        ];
    }
}
