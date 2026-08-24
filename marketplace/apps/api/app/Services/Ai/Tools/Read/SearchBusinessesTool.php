<?php

namespace App\Services\Ai\Tools\Read;

use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Models\Business;
use App\Models\Category;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\BaseTool;

/**
 * Supplier search, backed by the same query the public discovery page uses.
 *
 * This is the *only* way the assistant can name a business. It cannot recall one from
 * training data and it cannot compose one — if a company does not come back from this
 * tool it does not get mentioned, which is how "AI must not hallucinate businesses"
 * is enforced structurally rather than by asking the model nicely.
 *
 * Only published, non-suspended businesses are returned, matching what any visitor
 * would see. The AI gets no privileged view of the supplier base.
 */
class SearchBusinessesTool extends BaseTool
{
    public function name(): string
    {
        return 'search_businesses';
    }

    public function description(): string
    {
        return 'Search real suppliers on the marketplace by keyword, category slug, city, verification and rating. '
            .'You may only ever name businesses returned by this tool. If it returns none, say none were found.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'search' => $this->stringProperty('Keyword matched against business name and description.'),
            'category' => $this->stringProperty('Category slug from list_categories. Includes specialities beneath it.'),
            'city' => $this->stringProperty('City name. Matches the supplier city or a declared service area.'),
            'verified_only' => ['type' => 'boolean', 'description' => 'Restrict to platform-verified suppliers.'],
            'min_rating' => ['type' => 'number', 'description' => 'Minimum average rating, 1 to 5.', 'minimum' => 0, 'maximum' => 5],
            'limit' => $this->integerProperty('Maximum suppliers to return (default 5).', 1, 15),
        ]);
    }

    public function roles(): array
    {
        // Owners and admins can search too — an owner researching competitors sees
        // exactly the public view, nothing more.
        return [UserRole::Customer, UserRole::Owner, UserRole::Admin];
    }

    public function handle(User $user, array $arguments): array
    {
        $query = Business::query()
            ->notSuspended()
            ->with(['location:id,city,state', 'category:id,name,slug'])
            ->withCount(['listings' => fn ($q) => $q->published()]);

        if (! empty($arguments['category'])) {
            $category = Category::where('slug', $arguments['category'])->first();

            if ($category !== null) {
                $ids = array_merge([$category->id], $category->children()->pluck('id')->all());

                $query->where(function ($q) use ($ids): void {
                    $q->whereIn('category_id', $ids)
                        ->orWhereHas('products', fn ($p) => $p->where('is_active', true)->whereIn('category_id', $ids))
                        ->orWhereHas('services', fn ($s) => $s->where('is_active', true)->whereIn('category_id', $ids));
                });
            }
        }

        if (! empty($arguments['city'])) {
            $city = trim((string) $arguments['city']);

            $query->where(function ($q) use ($city): void {
                $q->whereHas('location', fn ($l) => $l->where('city', 'like', "%$city%"))
                    ->orWhereHas('serviceAreas', fn ($l) => $l->where('city', 'like', "%$city%"));
            });
        }

        if (! empty($arguments['search'])) {
            $search = trim((string) $arguments['search']);
            $query->where(fn ($q) => $q->where('name', 'like', "%$search%")->orWhere('about', 'like', "%$search%"));
        }

        if (! empty($arguments['verified_only'])) {
            $query->where('verification_status', VerificationStatus::Verified->value);
        }

        if (isset($arguments['min_rating']) && $arguments['min_rating'] !== null) {
            $query->where('rating', '>=', (float) $arguments['min_rating']);
        }

        $businesses = $query
            ->orderByRaw('verification_status = ? desc', [VerificationStatus::Verified->value])
            ->orderByRaw('rating IS NULL')
            ->orderByDesc('rating')
            ->limit($this->limit($arguments, 5, 15))
            ->get();

        return [
            'count' => $businesses->count(),
            'businesses' => $businesses->map(fn (Business $business): array => [
                // The slug is the handle the frontend turns into a profile link, so
                // the assistant's mentions become real navigation.
                'slug' => $business->slug,
                'name' => $business->name,
                'category' => $business->category?->name,
                'city' => $business->location?->city,
                'state' => $business->location?->state,
                'verified' => $business->verificationStatus() === VerificationStatus::Verified,
                // Null, not zero. An unrated supplier is unproven, not bad, and the
                // model must be able to tell the difference when it explains a match.
                'rating' => $business->rating !== null ? (float) $business->rating : null,
                'review_count' => (int) $business->review_count,
                'years_in_business' => $business->years_in_business,
                'accepting_work' => (bool) $business->is_available,
            ])->all(),
            'note' => $businesses->isEmpty()
                ? 'No suppliers matched. Tell the user plainly and suggest widening the search. Do not name any business.'
                : 'These are the only businesses you may name.',
        ];
    }
}
