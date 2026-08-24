<?php

namespace Tests\Unit;

use App\Enums\ListingStatus;
use App\Models\Business;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Location;
use App\Models\Product;
use App\Models\Requirement;
use App\Models\Service;
use App\Services\Matching\BusinessMatchScorer;
use App\Services\Matching\RequirementMatchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dimension-level tests for the matching algorithm.
 *
 * These assert the *rules*, not a snapshot of today's numbers: each test
 * reads the relevant weight/threshold back out of config and compares
 * against that, so re-tuning the marketplace doesn't invalidate the suite
 * while still catching a genuine behavioural regression.
 *
 * Every fixture uses factory-generated categories and locations — no city or
 * category name is hardcoded anywhere, mirroring the constraint the engine
 * itself is held to.
 */
class BusinessMatchScorerTest extends TestCase
{
    use RefreshDatabase;

    private BusinessMatchScorer $scorer;

    private Category $parentCategory;

    private Category $category;

    private Category $childCategory;

    private Category $unrelatedCategory;

    private Location $customerLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = app(BusinessMatchScorer::class);

        $this->parentCategory = Category::factory()->create();
        $this->category = Category::factory()->create(['parent_id' => $this->parentCategory->id]);
        $this->childCategory = Category::factory()->create(['parent_id' => $this->category->id]);
        $this->unrelatedCategory = Category::factory()->create();

        $this->customerLocation = Location::factory()->create();
    }

    /* ------------------------------------------------------------------ *
     | Helpers
     * ------------------------------------------------------------------ */

    private function requirement(array $attributes = []): Requirement
    {
        return Requirement::factory()->create(array_merge([
            'category_id' => $this->category->id,
            'location_id' => $this->customerLocation->id,
            'quantity' => 100,
            'unit' => 'pieces',
            'budget_min' => null,
            'budget_max' => null,
            'required_date' => null,
        ], $attributes));
    }

    private function business(array $attributes = []): Business
    {
        return Business::factory()->create(array_merge([
            'category_id' => $this->category->id,
            'location_id' => $this->customerLocation->id,
        ], $attributes));
    }

    /** @return array<string, array{score: float, weight: float, weight_percentage: float, points: float, reason: string|null}> */
    private function breakdown(Requirement $requirement, Business $business): array
    {
        return $this->scorer
            ->score(RequirementMatchContext::for($requirement), $business->fresh())
            ->breakdown;
    }

    private function dimension(Requirement $requirement, Business $business, string $dimension): float
    {
        return (float) $this->breakdown($requirement, $business)[$dimension]['score'];
    }

    private function weightConfig(string $key): array
    {
        return (array) config('marketplace.matching.'.$key);
    }

    /* ------------------------------------------------------------------ *
     | 1. Category matching
     * ------------------------------------------------------------------ */

    public function test_exact_primary_category_scores_the_configured_maximum(): void
    {
        $score = $this->dimension($this->requirement(), $this->business(), 'category');

        $this->assertSame((float) $this->weightConfig('category')['exact_primary'], $score);
    }

    public function test_parent_category_is_treated_as_a_related_match(): void
    {
        $business = $this->business(['category_id' => $this->parentCategory->id]);

        $this->assertSame(
            (float) $this->weightConfig('category')['related_primary'],
            $this->dimension($this->requirement(), $business, 'category')
        );
    }

    public function test_child_category_is_treated_as_a_related_match(): void
    {
        $business = $this->business(['category_id' => $this->childCategory->id]);

        $this->assertSame(
            (float) $this->weightConfig('category')['related_primary'],
            $this->dimension($this->requirement(), $business, 'category')
        );
    }

    public function test_unrelated_category_scores_zero(): void
    {
        $business = $this->business(['category_id' => $this->unrelatedCategory->id]);

        $this->assertSame(0.0, $this->dimension($this->requirement(), $business, 'category'));
    }

    /** A business that never declared a primary category is still matched off what it actually offers. */
    public function test_catalogue_is_used_when_no_primary_category_is_declared(): void
    {
        $business = $this->business(['category_id' => null]);
        Product::factory()->for($business)->create(['category_id' => $this->category->id, 'is_active' => true]);

        $this->assertSame(
            (float) $this->weightConfig('category')['exact_catalog'],
            $this->dimension($this->requirement(), $business, 'category')
        );
    }

    /* ------------------------------------------------------------------ *
     | 2. Capability matching
     * ------------------------------------------------------------------ */

    public function test_business_with_no_capabilities_scores_zero_on_capability(): void
    {
        $this->assertSame(0.0, $this->dimension($this->requirement(), $this->business(), 'capability'));
    }

    public function test_active_product_in_the_requested_category_scores_the_exact_base(): void
    {
        $business = $this->business();
        Product::factory()->for($business)->create([
            'category_id' => $this->category->id,
            'min_order' => 10,
            'max_order' => 1000,
            'is_active' => true,
        ]);

        $config = $this->weightConfig('capability');
        $expected = (float) $config['exact_category'] + (float) $config['quantity_within_range'];

        // Delta rather than identity: the stored sub-score is rounded, and
        // comparing summed floats exactly would be a false failure.
        $this->assertEqualsWithDelta($expected, $this->dimension($this->requirement(['quantity' => 100]), $business, 'capability'), 0.0001);
    }

    public function test_inactive_capabilities_are_ignored(): void
    {
        $business = $this->business();
        Product::factory()->for($business)->create(['category_id' => $this->category->id, 'is_active' => false]);

        $this->assertSame(0.0, $this->dimension($this->requirement(), $business, 'capability'));
    }

    public function test_capability_in_related_category_scores_lower_than_exact(): void
    {
        $exactBusiness = $this->business();
        Product::factory()->for($exactBusiness)->create(['category_id' => $this->category->id, 'min_order' => 1, 'max_order' => 1000, 'is_active' => true]);

        $relatedBusiness = $this->business();
        Product::factory()->for($relatedBusiness)->create(['category_id' => $this->childCategory->id, 'min_order' => 1, 'max_order' => 1000, 'is_active' => true]);

        $requirement = $this->requirement(['quantity' => 100]);

        $this->assertGreaterThan(
            $this->dimension($requirement, $relatedBusiness, 'capability'),
            $this->dimension($requirement, $exactBusiness, 'capability')
        );
    }

    /** Order-size fit: a 1,000-unit minimum is a poor fit for a 50-unit requirement. */
    public function test_quantity_below_minimum_order_earns_no_quantity_bonus(): void
    {
        $business = $this->business();
        Product::factory()->for($business)->create([
            'category_id' => $this->category->id,
            'min_order' => 1000,
            'max_order' => 5000,
            'is_active' => true,
        ]);

        $config = $this->weightConfig('capability');

        $this->assertEqualsWithDelta(
            (float) $config['exact_category'] + (float) $config['quantity_below_min'],
            $this->dimension($this->requirement(['quantity' => 50]), $business, 'capability'),
            0.0001
        );
    }

    public function test_quantity_above_maximum_order_earns_only_a_partial_bonus(): void
    {
        $business = $this->business();
        Product::factory()->for($business)->create([
            'category_id' => $this->category->id,
            'min_order' => 1,
            'max_order' => 100,
            'is_active' => true,
        ]);

        $config = $this->weightConfig('capability');

        $this->assertEqualsWithDelta(
            (float) $config['exact_category'] + (float) $config['quantity_above_max'],
            $this->dimension($this->requirement(['quantity' => 5000]), $business, 'capability'),
            0.0001
        );
    }

    public function test_missing_quantity_is_treated_as_unknown_not_as_a_failure(): void
    {
        $business = $this->business();
        Product::factory()->for($business)->create([
            'category_id' => $this->category->id,
            'min_order' => 1000,
            'max_order' => 5000,
            'is_active' => true,
        ]);

        $config = $this->weightConfig('capability');

        $this->assertEqualsWithDelta(
            (float) $config['exact_category'] + (float) $config['quantity_unknown'],
            $this->dimension($this->requirement(['quantity' => null]), $business, 'capability'),
            0.0001
        );
    }

    /* ------------------------------------------------------------------ *
     | 3. Location matching
     * ------------------------------------------------------------------ */

    public function test_business_in_the_same_city_scores_the_configured_maximum(): void
    {
        $this->assertSame(
            (float) $this->weightConfig('location')['same_city'],
            $this->dimension($this->requirement(), $this->business(), 'location')
        );
    }

    /** City is compared as normalised text, because location rows are created ad hoc. */
    public function test_city_comparison_ignores_case_and_whitespace(): void
    {
        $businessLocation = Location::factory()->create([
            'city' => '  '.strtoupper($this->customerLocation->city).' ',
            'state' => $this->customerLocation->state,
            'latitude' => null,
            'longitude' => null,
        ]);

        $business = $this->business(['location_id' => $businessLocation->id]);

        $this->assertSame(
            (float) $this->weightConfig('location')['same_city'],
            $this->dimension($this->requirement(), $business, 'location')
        );
    }

    public function test_declared_service_area_matches_even_when_the_business_sits_elsewhere(): void
    {
        $farAway = Location::factory()->create(['latitude' => null, 'longitude' => null]);
        $business = $this->business(['location_id' => $farAway->id]);

        // The service-area flow stores a city without area/locality, so this
        // is a different row than the requirement's location on purpose.
        $serviceArea = Location::factory()->create([
            'city' => $this->customerLocation->city,
            'state' => $this->customerLocation->state,
            'area' => null,
            'locality' => null,
        ]);
        $business->serviceAreas()->attach($serviceArea->id);

        $this->assertSame(
            (float) $this->weightConfig('location')['declared_service_area'],
            $this->dimension($this->requirement(), $business->fresh(), 'location')
        );
    }

    public function test_same_state_but_different_city_scores_the_state_value(): void
    {
        $sameState = Location::factory()->create([
            'state' => $this->customerLocation->state,
            'city' => $this->customerLocation->city.' Other',
            'latitude' => null,
            'longitude' => null,
        ]);

        $requirement = $this->requirement();
        $requirement->location->update(['latitude' => null, 'longitude' => null]);

        $this->assertSame(
            (float) $this->weightConfig('location')['same_state'],
            $this->dimension($requirement->fresh(), $this->business(['location_id' => $sameState->id]), 'location')
        );
    }

    public function test_different_city_and_state_falls_back_to_country(): void
    {
        $elsewhere = Location::factory()->create([
            'city' => $this->customerLocation->city.' Elsewhere',
            'state' => $this->customerLocation->state.' Elsewhere',
            'latitude' => null,
            'longitude' => null,
        ]);

        $requirement = $this->requirement();
        $requirement->location->update(['latitude' => null, 'longitude' => null]);

        $this->assertSame(
            (float) $this->weightConfig('location')['same_country'],
            $this->dimension($requirement->fresh(), $this->business(['location_id' => $elsewhere->id]), 'location')
        );
    }

    /** A requirement with no location can't judge the dimension, so it must not punish anyone. */
    public function test_requirement_without_a_location_scores_neutral(): void
    {
        $requirement = $this->requirement(['location_id' => null]);

        $this->assertSame(
            (float) $this->weightConfig('location')['unknown'],
            $this->dimension($requirement, $this->business(), 'location')
        );
    }

    public function test_nearby_coordinates_score_above_the_state_fallback(): void
    {
        $requirement = $this->requirement();
        $requirement->location->update(['latitude' => 20.0, 'longitude' => 78.0]);

        // ~10km north, in a differently named city and state.
        $nearby = Location::factory()->create([
            'city' => $this->customerLocation->city.' North',
            'state' => $this->customerLocation->state.' North',
            'latitude' => 20.09,
            'longitude' => 78.0,
        ]);

        $score = $this->dimension($requirement->fresh(), $this->business(['location_id' => $nearby->id]), 'location');

        $this->assertGreaterThan((float) $this->weightConfig('location')['nearby_floor'], $score);
        $this->assertLessThan((float) $this->weightConfig('location')['same_city'], $score);
    }

    /* ------------------------------------------------------------------ *
     | 4. Budget compatibility
     * ------------------------------------------------------------------ */

    private function publishedListing(Business $business, float $price, ?float $priceMax = null): Listing
    {
        return Listing::factory()->create([
            'business_id' => $business->id,
            'user_id' => $business->user_id,
            'category_id' => $this->category->id,
            'price' => $price,
            'price_max' => $priceMax,
            'status' => ListingStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function test_pricing_inside_the_budget_scores_the_configured_maximum(): void
    {
        $business = $this->business();
        $this->publishedListing($business, 500);

        $requirement = $this->requirement(['quantity' => 1, 'budget_min' => 100, 'budget_max' => 1000]);

        $this->assertSame(
            (float) $this->weightConfig('budget')['overlap'],
            $this->dimension($requirement, $business, 'budget')
        );
    }

    public function test_pricing_far_above_the_budget_scores_zero(): void
    {
        $business = $this->business();
        $this->publishedListing($business, 1_000_000);

        $requirement = $this->requirement(['quantity' => 1, 'budget_min' => 100, 'budget_max' => 1000]);

        $this->assertSame(0.0, $this->dimension($requirement, $business, 'budget'));
    }

    public function test_pricing_slightly_above_the_budget_scores_between_zero_and_full(): void
    {
        $business = $this->business();
        $this->publishedListing($business, 1100);

        $requirement = $this->requirement(['quantity' => 1, 'budget_min' => 100, 'budget_max' => 1000]);

        $score = $this->dimension($requirement, $business, 'budget');

        $this->assertGreaterThan(0.0, $score);
        $this->assertLessThan((float) $this->weightConfig('budget')['overlap'], $score);
    }

    public function test_pricing_below_the_budget_floor_is_still_compatible(): void
    {
        $business = $this->business();
        $this->publishedListing($business, 10);

        $requirement = $this->requirement(['quantity' => 1, 'budget_min' => 1000, 'budget_max' => 5000]);

        $this->assertSame(
            (float) $this->weightConfig('budget')['below_budget'],
            $this->dimension($requirement, $business, 'budget')
        );
    }

    public function test_no_published_pricing_is_neutral_rather_than_a_penalty(): void
    {
        $requirement = $this->requirement(['budget_min' => 100, 'budget_max' => 1000]);

        $this->assertSame(
            (float) $this->weightConfig('budget')['unknown'],
            $this->dimension($requirement, $this->business(), 'budget')
        );
    }

    public function test_requirement_without_a_budget_is_neutral(): void
    {
        $business = $this->business();
        $this->publishedListing($business, 999_999);

        $this->assertSame(
            (float) $this->weightConfig('budget')['unknown'],
            $this->dimension($this->requirement(), $business, 'budget')
        );
    }

    /** A unit rate times the requested quantity is scored as well as the raw price. */
    public function test_unit_price_is_scored_against_the_budget_for_the_whole_quantity(): void
    {
        $business = $this->business();
        $this->publishedListing($business, 100);

        // 100 x 50 units = 5,000, which lands inside the budget even though
        // the listing price alone (100) sits below the floor.
        $requirement = $this->requirement(['quantity' => 50, 'budget_min' => 4000, 'budget_max' => 6000]);

        $this->assertSame(
            (float) $this->weightConfig('budget')['overlap'],
            $this->dimension($requirement, $business, 'budget')
        );
    }

    /* ------------------------------------------------------------------ *
     | 5. Availability
     * ------------------------------------------------------------------ */

    public function test_unavailable_business_scores_zero_on_availability(): void
    {
        $business = $this->business(['is_available' => false]);

        $this->assertSame(0.0, $this->dimension($this->requirement(), $business, 'availability'));
    }

    public function test_available_business_without_a_deadline_scores_the_no_deadline_value(): void
    {
        $this->assertSame(
            (float) $this->weightConfig('availability')['no_deadline'],
            $this->dimension($this->requirement(['required_date' => null]), $this->business(), 'availability')
        );
    }

    public function test_turnaround_that_meets_the_required_date_scores_the_maximum(): void
    {
        $business = $this->business();
        Service::factory()->for($business)->create([
            'category_id' => $this->category->id,
            'estimated_delivery_time' => '3 days',
            'min_order' => 1,
            'is_active' => true,
        ]);

        $requirement = $this->requirement(['required_date' => now()->addDays(20)->toDateString()]);

        $this->assertSame(
            (float) $this->weightConfig('availability')['meets_deadline'],
            $this->dimension($requirement, $business, 'availability')
        );
    }

    public function test_turnaround_that_misses_the_required_date_is_penalised(): void
    {
        $business = $this->business();
        Service::factory()->for($business)->create([
            'category_id' => $this->category->id,
            'estimated_delivery_time' => '6 weeks',
            'min_order' => 1,
            'is_active' => true,
        ]);

        $requirement = $this->requirement(['required_date' => now()->addDays(5)->toDateString()]);

        $this->assertSame(
            (float) $this->weightConfig('availability')['misses_deadline'],
            $this->dimension($requirement, $business, 'availability')
        );
    }

    public function test_available_business_with_no_turnaround_information_scores_the_available_value(): void
    {
        $business = $this->business();
        Service::factory()->for($business)->create([
            'category_id' => $this->category->id,
            'estimated_delivery_time' => null,
            'is_active' => true,
        ]);

        $requirement = $this->requirement(['required_date' => now()->addDays(5)->toDateString()]);

        $this->assertSame(
            (float) $this->weightConfig('availability')['available'],
            $this->dimension($requirement, $business, 'availability')
        );
    }

    /* ------------------------------------------------------------------ *
     | 6. Trust / performance
     * ------------------------------------------------------------------ */

    public function test_verified_and_well_rated_business_outscores_an_unverified_unrated_one(): void
    {
        $trusted = Business::factory()->verified()->rated(5.0, 50)->create([
            'category_id' => $this->category->id,
            'location_id' => $this->customerLocation->id,
            'years_in_business' => 20,
        ]);

        $unknown = $this->business(['years_in_business' => 0]);

        $requirement = $this->requirement();

        $this->assertGreaterThan(
            $this->dimension($requirement, $unknown, 'trust'),
            $this->dimension($requirement, $trusted, 'trust')
        );
    }

    /** A single five-star review must not count as much as a well-reviewed five stars. */
    public function test_rating_is_shrunk_towards_neutral_until_enough_reviews_back_it(): void
    {
        $wellReviewed = Business::factory()->rated(5.0, 100)->create([
            'category_id' => $this->category->id,
            'location_id' => $this->customerLocation->id,
        ]);

        $barelyReviewed = Business::factory()->rated(5.0, 1)->create([
            'category_id' => $this->category->id,
            'location_id' => $this->customerLocation->id,
        ]);

        $requirement = $this->requirement();

        $this->assertGreaterThan(
            $this->dimension($requirement, $barelyReviewed, 'trust'),
            $this->dimension($requirement, $wellReviewed, 'trust')
        );
    }

    public function test_unrated_business_is_not_penalised_as_though_it_were_badly_rated(): void
    {
        $unrated = $this->business(['rating' => null, 'review_count' => 0, 'years_in_business' => 5]);
        $badlyRated = Business::factory()->rated(1.0, 50)->create([
            'category_id' => $this->category->id,
            'location_id' => $this->customerLocation->id,
            'years_in_business' => 5,
        ]);

        $requirement = $this->requirement();

        $this->assertGreaterThan(
            $this->dimension($requirement, $badlyRated, 'trust'),
            $this->dimension($requirement, $unrated, 'trust')
        );
    }

    /* ------------------------------------------------------------------ *
     | Score calculation
     * ------------------------------------------------------------------ */

    public function test_total_is_the_weighted_sum_of_every_dimension(): void
    {
        $business = $this->business();
        Product::factory()->for($business)->create(['category_id' => $this->category->id, 'min_order' => 1, 'max_order' => 500, 'is_active' => true]);

        $score = $this->scorer->score(RequirementMatchContext::for($this->requirement()), $business->fresh());

        $expected = round(array_sum(array_column($score->breakdown, 'points')), 2);

        $this->assertSame($expected, round($score->total, 2));
        $this->assertGreaterThanOrEqual(0.0, $score->total);
        $this->assertLessThanOrEqual(100.0, $score->total);
    }

    public function test_a_perfect_candidate_scores_close_to_one_hundred(): void
    {
        $business = Business::factory()->verified()->rated(5.0, 100)->create([
            'category_id' => $this->category->id,
            'location_id' => $this->customerLocation->id,
            'years_in_business' => 25,
        ]);
        Product::factory()->for($business)->create(['category_id' => $this->category->id, 'min_order' => 1, 'max_order' => 10_000, 'is_active' => true]);
        Service::factory()->for($business)->create(['category_id' => $this->category->id, 'estimated_delivery_time' => '2 days', 'is_active' => true]);
        $this->publishedListing($business, 500);

        $requirement = $this->requirement([
            'quantity' => 100,
            'budget_min' => 100,
            'budget_max' => 100_000,
            'required_date' => now()->addDays(30)->toDateString(),
        ]);

        $score = $this->scorer->score(RequirementMatchContext::for($requirement), $business->fresh());

        $this->assertGreaterThan(90.0, $score->total);
    }

    public function test_a_completely_irrelevant_business_scores_below_the_invitation_threshold(): void
    {
        $elsewhere = Location::factory()->create([
            'country' => 'US',
            'city' => $this->customerLocation->city.' Foreign',
            'state' => $this->customerLocation->state.' Foreign',
            'latitude' => null,
            'longitude' => null,
        ]);

        $business = $this->business([
            'category_id' => $this->unrelatedCategory->id,
            'location_id' => $elsewhere->id,
            'is_available' => false,
        ]);

        $requirement = $this->requirement(['quantity' => 100, 'budget_min' => 100, 'budget_max' => 500]);
        $requirement->location->update(['latitude' => null, 'longitude' => null]);

        $score = $this->scorer->score(RequirementMatchContext::for($requirement->fresh()), $business->fresh());

        $this->assertLessThan((float) config('marketplace.matching.minimum_score'), $score->total);
    }

    /** Weights are configuration, not code — proving the algorithm is genuinely tunable. */
    public function test_weights_are_configurable(): void
    {
        config()->set('marketplace.matching.weights', [
            'category' => 100,
            'capability' => 0,
            'location' => 0,
            'budget' => 0,
            'availability' => 0,
            'trust' => 0,
        ]);

        $business = $this->business();
        $score = $this->scorer->score(RequirementMatchContext::for($this->requirement()), $business->fresh());

        // Category is a perfect match, and it is now the only thing that counts.
        $this->assertSame(100.0, round($score->total, 2));
        $this->assertSame(0.0, $score->breakdown['location']['points']);
    }

    public function test_scoring_is_deterministic_for_the_same_inputs(): void
    {
        $business = $this->business();
        Product::factory()->for($business)->create(['category_id' => $this->category->id, 'is_active' => true]);
        $requirement = $this->requirement();

        $first = $this->scorer->score(RequirementMatchContext::for($requirement), $business->fresh());
        $second = $this->scorer->score(RequirementMatchContext::for($requirement), $business->fresh());

        $this->assertSame($first->total, $second->total);
        $this->assertSame($first->reasons, $second->reasons);
    }

    public function test_every_dimension_reports_a_human_readable_reason(): void
    {
        $business = $this->business();
        $score = $this->scorer->score(RequirementMatchContext::for($this->requirement()), $business->fresh());

        foreach (['category', 'capability', 'location', 'budget', 'availability', 'trust'] as $dimension) {
            $this->assertArrayHasKey($dimension, $score->breakdown);
            $this->assertNotEmpty($score->breakdown[$dimension]['reason'], $dimension.' should explain itself');
        }

        $this->assertNotEmpty($score->reasons);
    }
}
