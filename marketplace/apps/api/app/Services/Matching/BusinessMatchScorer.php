<?php

namespace App\Services\Matching;

use App\Enums\AvailabilityStatus;
use App\Enums\MatchResponseStatus;
use App\Models\Business;
use App\Models\Product;
use App\Models\RequirementMatch;
use App\Models\Service;
use Illuminate\Support\Collection;

/**
 * Scores a single business against a single requirement across the six
 * Phase 4 dimensions, and combines them into one weighted percentage.
 *
 * Design rules this class holds itself to:
 *
 *  - Deterministic. Same inputs, same output. No randomness, no learned
 *    weights, no AI. Re-running matching cannot reshuffle results.
 *  - Every weight, bonus and threshold comes from config('marketplace.matching').
 *    Nothing about the algorithm assumes a particular marketplace, and no
 *    business name, city, category or industry is ever hardcoded.
 *  - Sub-scores are normalised 0..1 so a dimension's influence is decided
 *    purely by its weight. Weights don't have to add up to 100 — they're
 *    normalised by their own total.
 *  - An unknowable dimension scores neutral, not zero. A requirement with no
 *    budget shouldn't drag every candidate down; a business simply can't be
 *    penalised for information the customer never supplied.
 *
 * The class is read-only: it never writes a match, never mutates the
 * business, and can be called safely to preview a score.
 */
class BusinessMatchScorer
{
    /**
     * Relations the scorer reads. MatchingService eager-loads exactly these
     * so scoring a pool of candidates stays a fixed number of queries.
     */
    public const REQUIRED_RELATIONS = [
        'location',
        'serviceAreas',
        'products.category',
        'services.category',
        'listings',
    ];

    public function score(RequirementMatchContext $context, Business $business): MatchScore
    {
        $capabilities = $this->matchingCapabilities($context, $business);

        $dimensions = [
            'category' => $this->scoreCategory($context, $business),
            'capability' => $this->scoreCapability($context, $capabilities),
            'location' => $this->scoreLocation($context, $business),
            'budget' => $this->scoreBudget($context, $business),
            'availability' => $this->scoreAvailability($context, $business, $capabilities),
            'trust' => $this->scoreTrust($business),
        ];

        return $this->combine($dimensions, $capabilities);
    }

    /* ------------------------------------------------------------------ *
     | Dimension 1 — Category match (default weight 25%)
     * ------------------------------------------------------------------ */

    /**
     * How close the business's declared trade is to what's being asked for.
     * The declared primary category is the strongest signal, but a business
     * that never set one is still matched off what it actually lists, so a
     * blank profile field doesn't hide an otherwise perfect supplier.
     */
    private function scoreCategory(RequirementMatchContext $context, Business $business): DimensionScore
    {
        $config = $this->config('category');
        $categoryLabel = $context->categoryName ?? 'the requested category';

        $best = 0.0;
        $reason = 'Does not operate in this category';

        if ($context->isExactCategory($business->category_id)) {
            $best = (float) $config['exact_primary'];
            $reason = sprintf('Primary category is exactly %s', $categoryLabel);
        } elseif ($context->isRelatedCategory($business->category_id)) {
            $best = (float) $config['related_primary'];
            $reason = sprintf('Primary category is closely related to %s', $categoryLabel);
        }

        $catalogCategoryIds = $this->catalogCategoryIds($business);

        if ($catalogCategoryIds->contains(fn (int $id) => $context->isExactCategory($id))) {
            $candidate = (float) $config['exact_catalog'];
            if ($candidate > $best) {
                $best = $candidate;
                $reason = sprintf('Catalogue includes %s', $categoryLabel);
            }
        } elseif ($catalogCategoryIds->contains(fn (int $id) => $context->isRelatedCategory($id))) {
            $candidate = (float) $config['related_catalog'];
            if ($candidate > $best) {
                $best = $candidate;
                $reason = sprintf('Catalogue covers a category closely related to %s', $categoryLabel);
            }
        }

        return DimensionScore::of($best, $reason);
    }

    /* ------------------------------------------------------------------ *
     | Dimension 2 — Capability match (default weight 20%)
     * ------------------------------------------------------------------ */

    /**
     * Whether the business actually offers something that fits — the right
     * kind of product/service, at the quantity being asked for. Category
     * says "they're in this trade"; capability says "they can do this job".
     *
     * @param  Collection<int, Product|Service>  $capabilities
     */
    private function scoreCapability(RequirementMatchContext $context, Collection $capabilities): DimensionScore
    {
        $config = $this->config('capability');

        if ($capabilities->isEmpty()) {
            return DimensionScore::of(0.0, 'No products or services listed that fit this requirement');
        }

        $exact = $capabilities->filter(fn ($capability) => $context->isExactCategory($capability->category_id));
        $related = $capabilities->filter(fn ($capability) => $context->isRelatedCategory($capability->category_id));

        if ($exact->isNotEmpty()) {
            $pool = $exact;
            $base = (float) $config['exact_category'];
            $reason = sprintf('Offers %s directly in this category', $this->describeCount($exact));
        } elseif ($related->isNotEmpty()) {
            $pool = $related;
            $base = (float) $config['related_category'];
            $reason = sprintf('Offers %s in a closely related category', $this->describeCount($related));
        } else {
            $pool = $capabilities;
            $base = (float) $config['uncategorised'];
            $reason = sprintf('Offers %s that may fit', $this->describeCount($capabilities));
        }

        [$quantityBonus, $quantityReason] = $this->scoreQuantityFit($context, $pool, $config);

        if ($quantityReason !== null) {
            $reason .= '. '.$quantityReason;
        }

        return DimensionScore::of($base + $quantityBonus, $reason);
    }

    /**
     * Order-size fit. A supplier whose minimum order is 1,000 units is a bad
     * match for someone who needs 50, even though the category is perfect —
     * and one whose maximum is 100 can only partially serve an order of 500,
     * which is a weak match rather than no match (large orders are commonly
     * split or negotiated).
     *
     * @param  Collection<int, Product|Service>  $pool
     * @return array{0: float, 1: string|null}
     */
    private function scoreQuantityFit(RequirementMatchContext $context, Collection $pool, array $config): array
    {
        if ($context->quantity === null) {
            return [(float) $config['quantity_unknown'], null];
        }

        $quantity = $context->quantity;
        $unit = $context->unit !== null ? ' '.$context->unit : '';
        $quantityLabel = rtrim(rtrim(number_format($quantity, 2, '.', ','), '0'), '.').$unit;

        $withinRange = false;
        $aboveMax = false;

        foreach ($pool as $capability) {
            $min = $capability->min_order !== null ? (float) $capability->min_order : null;
            // Services have no ceiling on order size in the schema — only
            // products declare a maximum, so a null max means "no ceiling".
            $max = ($capability instanceof Product && $capability->max_order !== null) ? (float) $capability->max_order : null;

            if ($min !== null && $quantity < $min) {
                continue;
            }

            if ($max !== null && $quantity > $max) {
                $aboveMax = true;

                continue;
            }

            $withinRange = true;
            break;
        }

        if ($withinRange) {
            return [(float) $config['quantity_within_range'], sprintf('Handles the requested quantity of %s', $quantityLabel)];
        }

        if ($aboveMax) {
            return [(float) $config['quantity_above_max'], sprintf('Requested quantity of %s is above their usual maximum order', $quantityLabel)];
        }

        return [(float) $config['quantity_below_min'], sprintf('Requested quantity of %s is below their minimum order', $quantityLabel)];
    }

    /* ------------------------------------------------------------------ *
     | Dimension 3 — Location match (default weight 20%)
     * ------------------------------------------------------------------ */

    /**
     * Proximity, judged in priority order: the business's own city, then the
     * service areas it explicitly declared, then coordinates, then state,
     * then country.
     *
     * Locations are free-text rows created on demand, and the business and
     * requirement flows populate them differently (a service area is stored
     * without an area/locality, a requirement often has one). Comparing
     * location_id would therefore silently under-match, so this compares
     * normalised city and state text instead.
     */
    private function scoreLocation(RequirementMatchContext $context, Business $business): DimensionScore
    {
        $config = $this->config('location');

        if ($context->city === null && $context->state === null) {
            return DimensionScore::of((float) $config['unknown'], 'No delivery location was specified on the requirement');
        }

        $cityLabel = $context->cityLabel ?? 'the requested area';
        $businessLocation = $business->location;

        $best = 0.0;
        $reason = 'Outside the requested area';

        // 1. Same city as the business's own registered location.
        if ($context->city !== null && RequirementMatchContext::normalise($businessLocation?->city) === $context->city) {
            $best = (float) $config['same_city'];
            $reason = sprintf('Based in %s', $cityLabel);
        }

        // 2. The business explicitly declared this city as a service area.
        if ($context->city !== null) {
            $servesCity = $business->serviceAreas
                ->contains(fn ($area) => RequirementMatchContext::normalise($area->city) === $context->city);

            if ($servesCity) {
                $candidate = (float) $config['declared_service_area'];
                if ($candidate > $best) {
                    $best = $candidate;
                    $reason = sprintf('Lists %s as a service area', $cityLabel);
                }
            }
        }

        // 3. Coordinates, when both sides happen to have them.
        $distanceKm = $this->distanceKm(
            $context->latitude,
            $context->longitude,
            $businessLocation?->latitude !== null ? (float) $businessLocation->latitude : null,
            $businessLocation?->longitude !== null ? (float) $businessLocation->longitude : null,
        );

        $radius = (float) $config['nearby_radius_km'];
        if ($distanceKm !== null && $radius > 0 && $distanceKm <= $radius) {
            $floor = (float) $config['nearby_floor'];
            $candidate = 1.0 - ((1.0 - $floor) * ($distanceKm / $radius));
            if ($candidate > $best) {
                $best = $candidate;
                $reason = sprintf('Approximately %d km from the delivery location', (int) round($distanceKm));
            }
        }

        // 4. Same state.
        if ($context->state !== null && RequirementMatchContext::normalise($businessLocation?->state) === $context->state) {
            $candidate = (float) $config['same_state'];
            if ($candidate > $best) {
                $best = $candidate;
                $reason = 'Operates in the same state';
            }
        }

        // 5. Same country — a weak signal, but not nothing.
        if ($context->country !== null && RequirementMatchContext::normalise($businessLocation?->country) === $context->country) {
            $candidate = (float) $config['same_country'];
            if ($candidate > $best) {
                $best = $candidate;
                $reason = 'Operates in the same country';
            }
        }

        return DimensionScore::of($best, $reason);
    }

    /* ------------------------------------------------------------------ *
     | Dimension 4 — Budget compatibility (default weight 15%)
     * ------------------------------------------------------------------ */

    /**
     * Compares the customer's budget against what the business's published
     * listings indicate it charges. There is no per-business price field, so
     * published listing prices in (or near) the requested category are the
     * only honest signal available.
     *
     * A listing price can legitimately mean either a unit rate or a total,
     * depending on the trade, and nothing in the schema distinguishes them.
     * Rather than guess, both readings are scored and the more favourable one
     * wins: a business is judged incompatible only if it's over budget under
     * *every* reasonable interpretation.
     *
     * No published pricing at all is neutral, not a penalty — "price on
     * request" is normal in B2B and says nothing about affordability.
     */
    private function scoreBudget(RequirementMatchContext $context, Business $business): DimensionScore
    {
        $config = $this->config('budget');

        if (! $context->hasBudget()) {
            return DimensionScore::of((float) $config['unknown'], 'No budget was specified on the requirement');
        }

        $band = $this->indicativePriceBand($context, $business);

        if ($band === null) {
            return DimensionScore::of((float) $config['unknown'], 'No published pricing available to compare against your budget');
        }

        [$low, $high] = $band;

        $asTotal = $this->budgetOverlapScore($low, $high, $context, $config);

        $quantity = $context->quantity !== null && $context->quantity > 0 ? $context->quantity : null;
        $asUnitRate = $quantity !== null
            ? $this->budgetOverlapScore($low * $quantity, $high * $quantity, $context, $config)
            : 0.0;

        $best = max($asTotal, $asUnitRate);

        $reason = match (true) {
            $best >= (float) $config['overlap'] => 'Published pricing sits inside your budget range',
            $best >= (float) $config['below_budget'] => 'Typically prices below your budget range',
            $best > 0.0 => 'Published pricing is somewhat above your budget',
            default => 'Published pricing is well above your budget',
        };

        return DimensionScore::of($best, $reason);
    }

    /**
     * Overlap between an estimated price range and the customer's budget.
     * Over budget tapers linearly to zero across a configurable tolerance
     * band rather than falling off a cliff, so a business 5% over budget
     * still ranks well ahead of one that's triple the price.
     */
    private function budgetOverlapScore(float $estimateLow, float $estimateHigh, RequirementMatchContext $context, array $config): float
    {
        $budgetFloor = $context->budgetMin ?? 0.0;
        $budgetCeiling = $context->budgetMax;

        // No ceiling given: anything at or above the floor is compatible.
        if ($budgetCeiling === null) {
            return $estimateHigh >= $budgetFloor
                ? (float) $config['overlap']
                : (float) $config['below_budget'];
        }

        if ($estimateLow <= $budgetCeiling && $estimateHigh >= $budgetFloor) {
            return (float) $config['overlap'];
        }

        // Cheaper than the customer expected to pay — still compatible.
        if ($estimateHigh < $budgetFloor) {
            return (float) $config['below_budget'];
        }

        $tolerance = $budgetCeiling * (float) $config['over_budget_tolerance'];

        if ($tolerance <= 0.0) {
            return 0.0;
        }

        $excess = $estimateLow - $budgetCeiling;

        return max(0.0, (1.0 - ($excess / $tolerance)) * (float) $config['overlap']);
    }

    /**
     * Cheapest and dearest published price across the business's listings,
     * preferring listings in the requested category family and falling back
     * to its whole published catalogue.
     *
     * @return array{0: float, 1: float}|null
     */
    private function indicativePriceBand(RequirementMatchContext $context, Business $business): ?array
    {
        $published = $business->listings
            ->filter(fn ($listing) => $listing->isPublished()
                && $listing->availability_status === AvailabilityStatus::Available
                && $listing->price !== null);

        if ($published->isEmpty()) {
            return null;
        }

        $relevant = $published->filter(fn ($listing) => $context->isExactCategory($listing->category_id) || $context->isRelatedCategory($listing->category_id));

        $pool = $relevant->isNotEmpty() ? $relevant : $published;

        $low = $pool->min(fn ($listing) => (float) $listing->price);
        $high = $pool->max(fn ($listing) => (float) ($listing->price_max ?? $listing->price));

        return [(float) $low, (float) max($low, $high)];
    }

    /* ------------------------------------------------------------------ *
     | Dimension 5 — Availability (default weight 10%)
     * ------------------------------------------------------------------ */

    /**
     * Whether the business is open to new work, and whether its typical
     * turnaround can realistically hit the customer's required date.
     *
     * A business that has switched itself unavailable scores zero — that's an
     * explicit "not right now", and no amount of category fit outranks it.
     *
     * @param  Collection<int, Product|Service>  $capabilities
     */
    private function scoreAvailability(RequirementMatchContext $context, Business $business, Collection $capabilities): DimensionScore
    {
        $config = $this->config('availability');

        if (! $business->is_available) {
            return DimensionScore::of((float) $config['unavailable'], 'Not currently accepting new work');
        }

        if ($context->daysUntilRequired === null) {
            return DimensionScore::of((float) $config['no_deadline'], 'Available and the requirement has no fixed deadline');
        }

        $leadTimeDays = $capabilities
            ->filter(fn ($capability) => $capability instanceof Service)
            ->map(fn (Service $service) => $this->parseLeadTimeDays($service->estimated_delivery_time))
            ->filter(fn (?int $days) => $days !== null)
            ->min();

        if ($leadTimeDays === null) {
            return DimensionScore::of((float) $config['available'], 'Currently accepting new work');
        }

        if ($leadTimeDays <= $context->daysUntilRequired) {
            return DimensionScore::of(
                (float) $config['meets_deadline'],
                sprintf('Typical turnaround of %d days meets your required date', $leadTimeDays)
            );
        }

        return DimensionScore::of(
            (float) $config['misses_deadline'],
            sprintf('Typical turnaround of %d days may miss your required date', $leadTimeDays)
        );
    }

    /**
     * Turnaround is stored as free text ("2 weeks", "3-5 days"), so this
     * reads the largest number present and scales it by the unit mentioned.
     * Largest, not smallest, because the conservative estimate is the one
     * that shouldn't over-promise on the customer's deadline. Unparseable
     * text yields null, which the caller treats as "unknown", never as zero.
     */
    private function parseLeadTimeDays(?string $estimate): ?int
    {
        if ($estimate === null || trim($estimate) === '') {
            return null;
        }

        if (! preg_match_all('/\d+(?:\.\d+)?/', $estimate, $matches) || $matches[0] === []) {
            return null;
        }

        $value = max(array_map('floatval', $matches[0]));
        $normalised = strtolower($estimate);

        $multiplier = match (true) {
            str_contains($normalised, 'month') => 30,
            str_contains($normalised, 'week') => 7,
            str_contains($normalised, 'hour') => 0,
            default => 1,
        };

        // "6 hours" is same-day: floor to zero days rather than reporting 6.
        return (int) ceil($value * $multiplier);
    }

    /* ------------------------------------------------------------------ *
     | Dimension 6 — Trust / performance (default weight 10%)
     * ------------------------------------------------------------------ */

    /**
     * Blends four independent trust signals, each of which degrades to
     * neutral when unknown so a brand new business is treated as unproven
     * rather than untrustworthy:
     *
     *  - verification: how many identity dimensions an admin actually checked
     *  - rating: shrunk towards neutral until enough reviews back it up
     *  - tenure: years in business, saturating at a configurable ceiling
     *  - responsiveness: whether it replies to match invitations at all
     */
    private function scoreTrust(Business $business): DimensionScore
    {
        $config = $this->config('trust');
        $neutral = (float) $config['neutral'];
        $weights = $config['weights'];

        $signals = [
            'verification' => $this->verificationSignal($business),
            'rating' => $this->ratingSignal($business, $config, $neutral),
            'tenure' => $this->tenureSignal($business, $config, $neutral),
            'responsiveness' => $this->responsivenessSignal($business, $config, $neutral),
        ];

        $totalWeight = array_sum(array_map('floatval', $weights));

        if ($totalWeight <= 0.0) {
            return DimensionScore::of($neutral, 'Trust signals are not weighted');
        }

        $score = 0.0;
        foreach ($signals as $key => $value) {
            $score += $value * (float) ($weights[$key] ?? 0);
        }
        $score /= $totalWeight;

        return DimensionScore::of($score, $this->describeTrust($business, $score, $neutral));
    }

    private function verificationSignal(Business $business): float
    {
        $checks = [
            $business->email_verified_at !== null,
            $business->phone_verified_at !== null,
            $business->business_verified_at !== null,
            $business->address_verified_at !== null,
        ];

        // GST is only applicable when the business actually claims a number —
        // a business without one is not penalised for never verifying it.
        if ($business->gst_number !== null) {
            $checks[] = $business->gst_verified_at !== null;
        }

        $verified = count(array_filter($checks));
        $signal = $verified / count($checks);

        // The admin's overall verified flag acts as a floor, so a business
        // marked verified never reads as completely unverified.
        return $business->is_verified ? max($signal, 0.5) : $signal;
    }

    private function ratingSignal(Business $business, array $config, float $neutral): float
    {
        if ($business->rating === null) {
            return $neutral;
        }

        $maxRating = (float) $config['max_rating'];
        if ($maxRating <= 0.0) {
            return $neutral;
        }

        $normalised = min(1.0, max(0.0, (float) $business->rating / $maxRating));

        $requiredReviews = (int) $config['rating_confidence_reviews'];
        $confidence = $requiredReviews > 0
            ? min(1.0, (int) $business->review_count / $requiredReviews)
            : 1.0;

        // Shrink towards neutral until the review count justifies the rating:
        // one five-star review is not the same evidence as fifty.
        return ($normalised * $confidence) + ($neutral * (1.0 - $confidence));
    }

    private function tenureSignal(Business $business, array $config, float $neutral): float
    {
        if ($business->years_in_business === null) {
            return $neutral;
        }

        $saturation = (float) $config['tenure_saturation_years'];

        if ($saturation <= 0.0) {
            return $neutral;
        }

        return min(1.0, max(0.0, (int) $business->years_in_business / $saturation));
    }

    /**
     * Measured from the business's own history of answering earlier match
     * invitations. `invited_matches_count` / `responded_matches_count` are
     * eager-loaded by MatchingService; when they're absent (a one-off score
     * outside a run) the counts are queried directly.
     */
    private function responsivenessSignal(Business $business, array $config, float $neutral): float
    {
        $invited = $business->invited_matches_count
            ?? RequirementMatch::query()->where('business_id', $business->id)->whereNotNull('invited_at')->count();

        $minimum = (int) $config['responsiveness_min_invitations'];

        if ($invited < max(1, $minimum)) {
            return $neutral;
        }

        $responded = $business->responded_matches_count
            ?? RequirementMatch::query()
                ->where('business_id', $business->id)
                ->where('response_status', '!=', MatchResponseStatus::Pending->value)
                ->count();

        return min(1.0, max(0.0, $responded / $invited));
    }

    private function describeTrust(Business $business, float $score, float $neutral): string
    {
        $parts = [];

        if ($business->rating !== null) {
            $parts[] = sprintf('Rated %s from %d review(s)', rtrim(rtrim(number_format((float) $business->rating, 1), '0'), '.'), (int) $business->review_count);
        }

        if ($business->isFullyVerified()) {
            $parts[] = 'fully verified profile';
        } elseif ($business->is_verified) {
            $parts[] = 'verified profile';
        }

        if ($business->years_in_business !== null && $business->years_in_business > 0) {
            $parts[] = sprintf('%d year(s) in business', (int) $business->years_in_business);
        }

        if ($parts === []) {
            return $score >= $neutral
                ? 'Limited trust history available yet'
                : 'Profile is not yet verified';
        }

        return ucfirst(implode(', ', $parts));
    }

    /* ------------------------------------------------------------------ *
     | Combination
     * ------------------------------------------------------------------ */

    /**
     * Weighted average of the dimensions, expressed as 0..100. Weights are
     * normalised by their own sum, so an operator can express them as
     * percentages, ratios or raw points and the maths still holds.
     *
     * @param  array<string, DimensionScore>  $dimensions
     * @param  Collection<int, Product|Service>  $capabilities
     */
    private function combine(array $dimensions, Collection $capabilities): MatchScore
    {
        $weights = config('marketplace.matching.weights', []);
        $totalWeight = array_sum(array_map('floatval', $weights));

        $breakdown = [];
        $points = 0.0;

        foreach ($dimensions as $key => $dimension) {
            $weight = (float) ($weights[$key] ?? 0);
            $share = $totalWeight > 0 ? ($weight / $totalWeight) : 0.0;
            $contribution = $dimension->score * $share * 100;
            $points += $contribution;

            $breakdown[$key] = [
                'score' => round($dimension->score, 4),
                'weight' => $weight,
                'weight_percentage' => round($share * 100, 2),
                'points' => round($contribution, 2),
                'reason' => $dimension->reason,
            ];
        }

        // Most influential explanation first — that's the one that actually
        // answers "why was I matched with this business?".
        $ordered = collect($breakdown)
            ->sortByDesc('points')
            ->map(fn (array $dimension) => $dimension['reason'])
            ->filter()
            ->values()
            ->all();

        return new MatchScore(
            total: round($points, 2),
            breakdown: $breakdown,
            reasons: $ordered,
            capabilities: $capabilities
                ->take(5)
                ->map(fn ($capability) => [
                    'type' => $capability instanceof Product ? 'product' : 'service',
                    'name' => (string) $capability->name,
                ])
                ->values()
                ->all(),
        );
    }

    /* ------------------------------------------------------------------ *
     | Shared helpers
     * ------------------------------------------------------------------ */

    /**
     * Active products and services relevant to the requirement, best fit
     * first: exact category, then related category, then uncategorised
     * entries (which a business may simply never have categorised).
     *
     * @return Collection<int, Product|Service>
     */
    private function matchingCapabilities(RequirementMatchContext $context, Business $business): Collection
    {
        $active = collect()
            ->merge($business->products->filter(fn (Product $product) => $product->is_active))
            ->merge($business->services->filter(fn (Service $service) => $service->is_active));

        return $active
            ->filter(fn ($capability) => $context->isExactCategory($capability->category_id)
                || $context->isRelatedCategory($capability->category_id)
                || $capability->category_id === null)
            ->sortBy(fn ($capability) => match (true) {
                $context->isExactCategory($capability->category_id) => 0,
                $context->isRelatedCategory($capability->category_id) => 1,
                default => 2,
            })
            ->values();
    }

    /**
     * Every category the business demonstrably operates in, inferred from
     * what it has actually published rather than only what it declared.
     *
     * @return Collection<int, int>
     */
    private function catalogCategoryIds(Business $business): Collection
    {
        return collect()
            ->merge($business->products->filter(fn (Product $p) => $p->is_active)->pluck('category_id'))
            ->merge($business->services->filter(fn (Service $s) => $s->is_active)->pluck('category_id'))
            ->merge($business->listings->filter(fn ($listing) => $listing->isPublished())->pluck('category_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    /** Great-circle distance in km, or null when either side lacks coordinates. */
    private function distanceKm(?float $lat1, ?float $lon1, ?float $lat2, ?float $lon2): ?float
    {
        if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) {
            return null;
        }

        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function describeCount(Collection $capabilities): string
    {
        $count = $capabilities->count();
        $names = $capabilities->take(2)->pluck('name')->filter()->implode(', ');

        $label = $count === 1 ? '1 offering' : sprintf('%d offerings', $count);

        return $names !== '' ? sprintf('%s (%s)', $label, $names) : $label;
    }

    private function config(string $key): array
    {
        return (array) config('marketplace.matching.'.$key, []);
    }
}
