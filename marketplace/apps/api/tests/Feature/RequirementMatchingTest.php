<?php

namespace Tests\Feature;

use App\Enums\MatchResponseStatus;
use App\Enums\RequirementStatus;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\Requirement;
use App\Models\RequirementMatch;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end coverage of the Phase 4 flow: publishing a requirement runs the
 * engine, the customer sees ranked businesses with the reasoning, the matched
 * business sees the requirement in its inbox and can respond — and neither
 * side can reach anything that isn't theirs.
 *
 * Fixtures are factory generated (no hardcoded city or category) so the tests
 * exercise the rules rather than a particular marketplace's data.
 */
class RequirementMatchingTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Location $location;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::factory()->create();
        $this->location = Location::factory()->create();
        $this->customer = User::factory()->create(['role' => UserRole::Customer]);
    }

    /* ------------------------------------------------------------------ *
     | Fixtures
     * ------------------------------------------------------------------ */

    /** A business that should comfortably clear the matching threshold. */
    private function suitableBusiness(array $attributes = []): Business
    {
        $business = Business::factory()->verified()->rated()->create(array_merge([
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'years_in_business' => 12,
            'is_available' => true,
        ], $attributes));

        Product::factory()->for($business)->create([
            'category_id' => $this->category->id,
            'min_order' => 1,
            'max_order' => 10_000,
            'is_active' => true,
        ]);

        Service::factory()->for($business)->create([
            'category_id' => $this->category->id,
            'estimated_delivery_time' => '3 days',
            'min_order' => 1,
            'is_active' => true,
        ]);

        return $business;
    }

    /** A business with nothing to do with the requirement — different trade, different place. */
    private function irrelevantBusiness(): Business
    {
        $elsewhere = Location::factory()->create([
            'city' => $this->location->city.' Far',
            'state' => $this->location->state.' Far',
            'latitude' => null,
            'longitude' => null,
        ]);

        return Business::factory()->create([
            'category_id' => Category::factory()->create()->id,
            'location_id' => $elsewhere->id,
        ]);
    }

    private function requirement(array $attributes = [], ?User $customer = null): Requirement
    {
        return Requirement::factory()->create(array_merge([
            'customer_id' => ($customer ?? $this->customer)->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'title' => 'Bulk order enquiry',
            'quantity' => 250,
            'unit' => 'pieces',
            'budget_min' => 10_000,
            'budget_max' => 90_000,
            'required_date' => now()->addDays(30)->toDateString(),
            'status' => RequirementStatus::Draft,
        ], $attributes));
    }

    private function publishedRequirement(array $attributes = [], ?User $customer = null): Requirement
    {
        return $this->requirement(array_merge([
            'status' => RequirementStatus::Published,
            'published_at' => now(),
        ], $attributes), $customer);
    }

    private function match(Business $business, ?Requirement $requirement = null, array $attributes = []): RequirementMatch
    {
        return RequirementMatch::factory()->create(array_merge([
            'requirement_id' => ($requirement ?? $this->publishedRequirement())->id,
            'business_id' => $business->id,
        ], $attributes));
    }

    /* ------------------------------------------------------------------ *
     | Match creation
     * ------------------------------------------------------------------ */

    /** Publishing is the trigger — the customer never has to ask the marketplace to look. */
    public function test_publishing_a_requirement_automatically_creates_matches(): void
    {
        $business = $this->suitableBusiness();
        $requirement = $this->requirement();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/requirements/{$requirement->uuid}/publish")
            ->assertOk();

        $this->assertDatabaseHas('requirement_matches', [
            'requirement_id' => $requirement->id,
            'business_id' => $business->id,
            'response_status' => MatchResponseStatus::Pending->value,
        ]);

        $match = RequirementMatch::first();
        $this->assertNotNull($match->invited_at);
        $this->assertNull($match->viewed_at);
        $this->assertGreaterThan(0, (float) $match->match_score);
        $this->assertNotEmpty($match->reasons());
    }

    /** A successful match run moves the requirement on to the Matching stage. */
    public function test_requirement_moves_to_matching_status_once_businesses_are_found(): void
    {
        $this->suitableBusiness();
        $requirement = $this->requirement();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/requirements/{$requirement->uuid}/publish")
            ->assertOk()
            ->assertJsonPath('data.attributes.status', RequirementStatus::Matching->value);
    }

    /** With nobody suitable, the requirement stays published rather than claiming to be matching. */
    public function test_requirement_stays_published_when_nothing_matches(): void
    {
        $this->irrelevantBusiness();
        $requirement = $this->requirement();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/requirements/{$requirement->uuid}/publish")
            ->assertOk()
            ->assertJsonPath('data.attributes.status', RequirementStatus::Published->value);

        $this->assertDatabaseCount('requirement_matches', 0);
    }

    public function test_irrelevant_business_is_never_invited(): void
    {
        $suitable = $this->suitableBusiness();
        $irrelevant = $this->irrelevantBusiness();
        $requirement = $this->publishedRequirement();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/requirements/{$requirement->uuid}/matches")
            ->assertOk();

        $this->assertDatabaseHas('requirement_matches', ['business_id' => $suitable->id]);
        $this->assertDatabaseMissing('requirement_matches', ['business_id' => $irrelevant->id]);
    }

    /** Re-running refreshes the score but must not reset the invitation or a reply. */
    public function test_re_running_matching_is_idempotent_and_preserves_responses(): void
    {
        $business = $this->suitableBusiness();
        $requirement = $this->publishedRequirement();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/requirements/{$requirement->uuid}/matches")
            ->assertOk();

        $match = RequirementMatch::firstOrFail();
        $match->update([
            'response_status' => MatchResponseStatus::Accepted,
            'responded_at' => now()->subDay(),
            'viewed_at' => now()->subDay(),
        ]);
        $originalInvitedAt = $match->invited_at;

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/requirements/{$requirement->uuid}/matches")
            ->assertOk();

        $this->assertDatabaseCount('requirement_matches', 1);

        $refreshed = $match->fresh();
        $this->assertSame(MatchResponseStatus::Accepted, $refreshed->response_status);
        $this->assertNotNull($refreshed->viewed_at);
        $this->assertSame($originalInvitedAt->toDateTimeString(), $refreshed->invited_at->toDateTimeString());
        $this->assertSame($business->id, $refreshed->business_id);
    }

    /** A draft has not been offered to anyone, so there is nothing to match. */
    public function test_matching_cannot_be_run_on_a_draft_requirement(): void
    {
        $this->suitableBusiness();
        $requirement = $this->requirement();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/requirements/{$requirement->uuid}/matches")
            ->assertForbidden();

        $this->assertDatabaseCount('requirement_matches', 0);
    }

    /** A customer who also owns a business is never matched with themselves. */
    public function test_customer_is_not_matched_with_their_own_business(): void
    {
        $ownBusiness = $this->suitableBusiness(['user_id' => $this->customer->id]);
        $requirement = $this->publishedRequirement();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/requirements/{$requirement->uuid}/matches")
            ->assertOk();

        $this->assertDatabaseMissing('requirement_matches', ['business_id' => $ownBusiness->id]);
    }

    public function test_matches_are_ranked_with_the_best_score_first(): void
    {
        $strong = $this->suitableBusiness();
        // Same trade, but unverified, unrated and not local — a weaker fit.
        $weakLocation = Location::factory()->create([
            'city' => $this->location->city.' Outer',
            'latitude' => null,
            'longitude' => null,
        ]);
        $weak = Business::factory()->create([
            'category_id' => $this->category->id,
            'location_id' => $weakLocation->id,
        ]);

        $requirement = $this->publishedRequirement();

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/requirements/{$requirement->uuid}/matches")
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('relationships.business.id')->all();

        $this->assertSame($strong->id, $ids[0]);
        $this->assertContains($weak->id, $ids);
        $this->assertGreaterThan(
            (float) $response->json('data.1.attributes.match_score'),
            (float) $response->json('data.0.attributes.match_score')
        );
    }

    /* ------------------------------------------------------------------ *
     | Customer results
     * ------------------------------------------------------------------ */

    /** Everything the customer is promised: business, match %, why, capabilities, location, rating, availability, trust. */
    public function test_customer_sees_matched_businesses_with_the_full_explanation(): void
    {
        $business = $this->suitableBusiness();
        $requirement = $this->publishedRequirement();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/requirements/{$requirement->uuid}/matches")
            ->assertOk();

        $response = $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/requirements/{$requirement->uuid}/matches")
            ->assertOk();

        $response->assertJsonPath('data.0.type', 'requirement_match');
        $response->assertJsonPath('data.0.relationships.business.attributes.name', $business->name);
        $response->assertJsonPath('data.0.relationships.business.relationships.location.attributes.city', $this->location->city);
        $response->assertJsonPath('data.0.relationships.business.attributes.is_available', true);
        $response->assertJsonPath('data.0.relationships.business.attributes.is_verified', true);
        $response->assertJsonPath('data.0.attributes.response_status', MatchResponseStatus::Pending->value);

        $attributes = $response->json('data.0.attributes');
        $this->assertGreaterThan(0, $attributes['match_percentage']);
        $this->assertLessThanOrEqual(100, $attributes['match_percentage']);
        $this->assertNotEmpty($attributes['reasons'], 'the customer must be told why this business was matched');
        $this->assertNotEmpty($attributes['capabilities'], 'relevant capabilities must be surfaced');
        $this->assertNotNull($response->json('data.0.relationships.business.attributes.rating'));

        foreach (['category', 'capability', 'location', 'budget', 'availability', 'trust'] as $dimension) {
            $this->assertArrayHasKey($dimension, $attributes['score_breakdown']);
        }
    }

    public function test_customer_sees_an_empty_list_before_matching_has_run(): void
    {
        $requirement = $this->requirement();

        $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/requirements/{$requirement->uuid}/matches")
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_customer_sees_how_matched_businesses_have_responded(): void
    {
        $requirement = $this->publishedRequirement();
        $this->match($this->suitableBusiness(), $requirement, [
            'response_status' => MatchResponseStatus::Accepted,
            'responded_at' => now(),
        ]);

        $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/requirements/{$requirement->uuid}/matches")
            ->assertOk()
            ->assertJsonPath('data.0.attributes.response_status', MatchResponseStatus::Accepted->value)
            ->assertJsonPath('meta.response_counts.accepted', 1)
            ->assertJsonPath('meta.response_counts.pending', 0);
    }

    /** The customer is a spectator on the business's decision, not a participant. */
    public function test_customer_cannot_respond_on_behalf_of_a_matched_business(): void
    {
        $requirement = $this->publishedRequirement();
        $match = $this->match($this->suitableBusiness(), $requirement);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/matches/{$match->uuid}/accept")
            ->assertForbidden();

        $this->assertSame(MatchResponseStatus::Pending, $match->fresh()->response_status);
        $this->assertFalse(
            $this->actingAs($this->customer, 'sanctum')
                ->getJson("/api/v1/matches/{$match->uuid}")
                ->json('data.attributes.can_respond')
        );
    }

    /* ------------------------------------------------------------------ *
     | Business results
     * ------------------------------------------------------------------ */

    public function test_business_sees_matched_requirements_with_the_details_it_needs(): void
    {
        $business = $this->suitableBusiness();
        $requirement = $this->publishedRequirement();
        $this->match($business, $requirement, ['match_score' => 88.5]);

        $response = $this->actingAs($business->user, 'sanctum')
            ->getJson('/api/v1/users/me/matched-requirements')
            ->assertOk();

        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.attributes.match_percentage', 89);
        $response->assertJsonPath('data.0.relationships.requirement.attributes.title', 'Bulk order enquiry');
        $response->assertJsonPath('data.0.relationships.requirement.relationships.category.attributes.name', $this->category->name);
        $response->assertJsonPath('data.0.relationships.requirement.relationships.location.attributes.city', $this->location->city);
        $response->assertJsonPath('data.0.relationships.requirement.attributes.quantity', '250.00');
        $response->assertJsonPath('data.0.relationships.requirement.attributes.budget_max', '90000.00');
        $response->assertJsonPath('data.0.relationships.requirement.attributes.required_date', $requirement->required_date->toDateString());
        $response->assertJsonPath('data.0.attributes.can_respond', true);
    }

    /** A business must never learn who raised the requirement from its inbox. */
    public function test_business_inbox_does_not_expose_the_customer_identity(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Confidential Buyer']);
        $business = $this->suitableBusiness();
        $this->match($business, $this->publishedRequirement([], $customer));

        $this->actingAs($business->user, 'sanctum')
            ->getJson('/api/v1/users/me/matched-requirements')
            ->assertOk()
            ->assertJsonMissing(['name' => 'Confidential Buyer']);
    }

    /** Only requirements still open to businesses appear — a cancelled one drops out. */
    public function test_business_inbox_excludes_requirements_that_are_no_longer_open(): void
    {
        $business = $this->suitableBusiness();
        $open = $this->publishedRequirement(['title' => 'Still open']);
        $cancelled = $this->publishedRequirement([
            'title' => 'Withdrawn',
            'status' => RequirementStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        $this->match($business, $open);
        $this->match($business, $cancelled);

        $this->actingAs($business->user, 'sanctum')
            ->getJson('/api/v1/users/me/matched-requirements')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Still open'])
            ->assertJsonMissing(['title' => 'Withdrawn']);
    }

    public function test_business_inbox_can_be_filtered_by_response_status(): void
    {
        $business = $this->suitableBusiness();
        $this->match($business, $this->publishedRequirement(['title' => 'Awaiting reply']));
        $this->match($business, $this->publishedRequirement(['title' => 'Already declined']), [
            'response_status' => MatchResponseStatus::Declined,
            'responded_at' => now(),
        ]);

        $this->actingAs($business->user, 'sanctum')
            ->getJson('/api/v1/users/me/matched-requirements?filter[response_status]=pending')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['title' => 'Awaiting reply'])
            ->assertJsonMissing(['title' => 'Already declined']);
    }

    public function test_opening_a_match_records_that_the_business_viewed_it(): void
    {
        $business = $this->suitableBusiness();
        $match = $this->match($business);

        $this->assertNull($match->viewed_at);

        $this->actingAs($business->user, 'sanctum')
            ->getJson("/api/v1/matches/{$match->uuid}")
            ->assertOk()
            ->assertJsonPath('data.id', $match->uuid);

        $this->assertNotNull($match->fresh()->viewed_at);
    }

    /** The customer reading a match must not make it look like the business has seen it. */
    public function test_customer_opening_a_match_does_not_mark_it_viewed(): void
    {
        $requirement = $this->publishedRequirement();
        $match = $this->match($this->suitableBusiness(), $requirement);

        $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/matches/{$match->uuid}")
            ->assertOk();

        $this->assertNull($match->fresh()->viewed_at);
    }

    public function test_business_can_accept_a_matched_requirement(): void
    {
        $business = $this->suitableBusiness();
        $match = $this->match($business);

        $this->actingAs($business->user, 'sanctum')
            ->postJson("/api/v1/matches/{$match->uuid}/accept")
            ->assertOk()
            ->assertJsonPath('data.attributes.response_status', MatchResponseStatus::Accepted->value)
            ->assertJsonPath('data.attributes.can_respond', false);

        $refreshed = $match->fresh();
        $this->assertSame(MatchResponseStatus::Accepted, $refreshed->response_status);
        $this->assertNotNull($refreshed->responded_at);
        $this->assertNotNull($refreshed->viewed_at);
    }

    public function test_business_can_decline_a_matched_requirement_with_a_note(): void
    {
        $business = $this->suitableBusiness();
        $match = $this->match($business);

        $this->actingAs($business->user, 'sanctum')
            ->postJson("/api/v1/matches/{$match->uuid}/decline", ['note' => 'No capacity this month.'])
            ->assertOk()
            ->assertJsonPath('data.attributes.response_status', MatchResponseStatus::Declined->value)
            ->assertJsonPath('data.attributes.response_note', 'No capacity this month.');
    }

    /** Asking is not deciding — a question leaves the response open. */
    public function test_business_can_ask_a_question_without_committing(): void
    {
        $business = $this->suitableBusiness();
        $match = $this->match($business);

        $this->actingAs($business->user, 'sanctum')
            ->postJson("/api/v1/matches/{$match->uuid}/question", ['question' => 'Is the quantity negotiable?'])
            ->assertOk()
            ->assertJsonPath('data.attributes.question', 'Is the quantity negotiable?')
            ->assertJsonPath('data.attributes.response_status', MatchResponseStatus::Pending->value)
            ->assertJsonPath('data.attributes.can_respond', true);

        $this->assertNotNull($match->fresh()->question_asked_at);
    }

    public function test_question_is_validated(): void
    {
        $business = $this->suitableBusiness();
        $match = $this->match($business);

        $this->actingAs($business->user, 'sanctum')
            ->postJson("/api/v1/matches/{$match->uuid}/question", ['question' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['question']);
    }

    public function test_business_cannot_respond_twice(): void
    {
        $business = $this->suitableBusiness();
        $match = $this->match($business);

        $this->actingAs($business->user, 'sanctum')
            ->postJson("/api/v1/matches/{$match->uuid}/accept")
            ->assertOk();

        $this->actingAs($business->user, 'sanctum')
            ->postJson("/api/v1/matches/{$match->uuid}/decline")
            ->assertStatus(422);

        $this->assertSame(MatchResponseStatus::Accepted, $match->fresh()->response_status);
    }

    public function test_business_cannot_respond_to_a_cancelled_requirement(): void
    {
        $business = $this->suitableBusiness();
        $match = $this->match($business, $this->publishedRequirement([
            'status' => RequirementStatus::Cancelled,
            'cancelled_at' => now(),
        ]));

        $this->actingAs($business->user, 'sanctum')
            ->postJson("/api/v1/matches/{$match->uuid}/accept")
            ->assertStatus(422);

        $this->assertSame(MatchResponseStatus::Pending, $match->fresh()->response_status);
    }

    /* ------------------------------------------------------------------ *
     | Unauthorized access
     * ------------------------------------------------------------------ */

    public function test_guest_cannot_access_any_matching_endpoint(): void
    {
        $business = $this->suitableBusiness();
        $requirement = $this->publishedRequirement();
        $match = $this->match($business, $requirement);

        $this->getJson("/api/v1/requirements/{$requirement->uuid}/matches")->assertUnauthorized();
        $this->postJson("/api/v1/requirements/{$requirement->uuid}/matches")->assertUnauthorized();
        $this->getJson('/api/v1/users/me/matched-requirements')->assertUnauthorized();
        $this->getJson("/api/v1/matches/{$match->uuid}")->assertUnauthorized();
        $this->postJson("/api/v1/matches/{$match->uuid}/accept")->assertUnauthorized();
    }

    /** A customer can only ever see the matches on their own requirements. */
    public function test_customer_cannot_view_matches_on_another_customers_requirement(): void
    {
        $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $requirement = $this->publishedRequirement([], $otherCustomer);
        $this->match($this->suitableBusiness(), $requirement);

        $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/requirements/{$requirement->uuid}/matches")
            ->assertForbidden();
    }

    public function test_customer_cannot_run_matching_on_another_customers_requirement(): void
    {
        $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $requirement = $this->publishedRequirement([], $otherCustomer);
        $this->suitableBusiness();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/requirements/{$requirement->uuid}/matches")
            ->assertForbidden();

        $this->assertDatabaseCount('requirement_matches', 0);
    }

    /**
     * A business may read a published requirement, but the match list is
     * off limits — it would reveal which competitors were invited and how
     * they scored.
     */
    public function test_matched_business_cannot_read_the_full_match_list_for_a_requirement(): void
    {
        $business = $this->suitableBusiness();
        $requirement = $this->publishedRequirement();
        $this->match($business, $requirement);

        $this->actingAs($business->user, 'sanctum')
            ->getJson("/api/v1/requirements/{$requirement->uuid}/matches")
            ->assertForbidden();
    }

    /** Being invited to one requirement grants no access to another business's invitation. */
    public function test_business_cannot_view_a_match_belonging_to_another_business(): void
    {
        $requirement = $this->publishedRequirement();
        $invited = $this->suitableBusiness();
        $outsider = $this->suitableBusiness();

        $match = $this->match($invited, $requirement);

        $this->actingAs($outsider->user, 'sanctum')
            ->getJson("/api/v1/matches/{$match->uuid}")
            ->assertForbidden();

        $this->actingAs($outsider->user, 'sanctum')
            ->postJson("/api/v1/matches/{$match->uuid}/accept")
            ->assertForbidden();

        $this->assertSame(MatchResponseStatus::Pending, $match->fresh()->response_status);
    }

    public function test_business_inbox_only_contains_its_own_invitations(): void
    {
        $mine = $this->suitableBusiness();
        $theirs = $this->suitableBusiness();

        $this->match($mine, $this->publishedRequirement(['title' => 'Invited to me']));
        $this->match($theirs, $this->publishedRequirement(['title' => 'Invited to them']));

        $this->actingAs($mine->user, 'sanctum')
            ->getJson('/api/v1/users/me/matched-requirements')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['title' => 'Invited to me'])
            ->assertJsonMissing(['title' => 'Invited to them']);
    }

    /** The matched-requirements inbox is a business-side view; a customer has no business using it. */
    public function test_customer_cannot_use_the_business_matched_requirements_inbox(): void
    {
        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/users/me/matched-requirements')
            ->assertForbidden();
    }

    /** An owner without a business profile yet gets an empty inbox, not an error. */
    public function test_owner_without_a_business_profile_sees_an_empty_inbox(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/users/me/matched-requirements')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    /** Admins can read a match for support purposes but cannot answer for the business. */
    public function test_admin_can_view_a_match_but_cannot_respond_for_the_business(): void
    {
        $admin = User::factory()->admin()->create();
        $match = $this->match($this->suitableBusiness());

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/matches/{$match->uuid}")
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/matches/{$match->uuid}/accept")
            ->assertForbidden();
    }
}
