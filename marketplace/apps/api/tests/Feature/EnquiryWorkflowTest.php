<?php

namespace Tests\Feature;

use App\Enums\EnquiryStatus;
use App\Enums\MatchResponseStatus;
use App\Enums\RequirementStatus;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Category;
use App\Models\Enquiry;
use App\Models\Location;
use App\Models\Requirement;
use App\Models\RequirementMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5 — the enquiry half of the flow: a matched business is asked, decides,
 * and either unlocks quoting or closes the conversation.
 *
 * Fixtures are factory-generated across arbitrary categories and locations. No
 * city, state or industry is hardcoded anywhere, so these tests would pass for a
 * construction business in Coimbatore exactly as they do for an IT firm in
 * Indore.
 */
class EnquiryWorkflowTest extends TestCase
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

    private function business(): Business
    {
        return Business::factory()->create([
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
        ]);
    }

    private function requirement(?User $customer = null, array $overrides = []): Requirement
    {
        return Requirement::factory()->create(array_merge([
            'customer_id' => ($customer ?? $this->customer)->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'status' => RequirementStatus::Published,
            'published_at' => now(),
        ], $overrides));
    }

    private function match(Requirement $requirement, Business $business): RequirementMatch
    {
        return RequirementMatch::factory()->create([
            'requirement_id' => $requirement->id,
            'business_id' => $business->id,
        ]);
    }

    private function enquiry(Requirement $requirement, Business $business, array $overrides = []): Enquiry
    {
        return Enquiry::factory()->create(array_merge([
            'requirement_id' => $requirement->id,
            'customer_id' => $requirement->customer_id,
            'business_id' => $business->id,
            'match_id' => $this->match($requirement, $business)->id,
        ], $overrides));
    }

    /* ------------------------------------------------------------------ *
     | Creating an enquiry
     * ------------------------------------------------------------------ */

    public function test_customer_opens_an_enquiry_with_a_matched_business(): void
    {
        $business = $this->business();
        $requirement = $this->requirement();
        $match = $this->match($requirement, $business);

        $response = $this->actingAs($this->customer, 'sanctum')->postJson(
            "/api/v1/requirements/{$requirement->uuid}/enquiries",
            ['match_id' => $match->uuid, 'message' => 'Can you handle this volume?']
        );

        $response->assertCreated();
        $response->assertJsonPath('data.attributes.status', EnquiryStatus::Pending->value);
        $response->assertJsonPath('data.attributes.customer_message', 'Can you handle this volume?');

        $this->assertDatabaseHas('enquiries', [
            'requirement_id' => $requirement->id,
            'business_id' => $business->id,
            'customer_id' => $this->customer->id,
            'status' => EnquiryStatus::Pending->value,
        ]);
    }

    /** Sending twice must not create two conversations — the unique pair is enforced. */
    public function test_opening_the_same_enquiry_twice_is_idempotent(): void
    {
        $business = $this->business();
        $requirement = $this->requirement();
        $match = $this->match($requirement, $business);
        $payload = ['match_id' => $match->uuid, 'message' => 'Hello'];

        $first = $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/requirements/{$requirement->uuid}/enquiries", $payload);
        $second = $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/requirements/{$requirement->uuid}/enquiries", $payload);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('enquiries', 1);
    }

    /** Ownership is derived from the requirement and match, never from the body. */
    public function test_customer_cannot_open_an_enquiry_on_another_customers_requirement(): void
    {
        $business = $this->business();
        $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $requirement = $this->requirement($otherCustomer);
        $match = $this->match($requirement, $business);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/requirements/{$requirement->uuid}/enquiries", ['match_id' => $match->uuid])
            ->assertForbidden();

        $this->assertDatabaseCount('enquiries', 0);
    }

    /** A match from a different requirement cannot be used to reach a business. */
    public function test_match_must_belong_to_the_requirement(): void
    {
        $business = $this->business();
        $requirement = $this->requirement();
        $otherRequirement = $this->requirement();
        $foreignMatch = $this->match($otherRequirement, $business);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/requirements/{$requirement->uuid}/enquiries", ['match_id' => $foreignMatch->uuid])
            ->assertStatus(422);
    }

    public function test_cannot_open_an_enquiry_on_a_cancelled_requirement(): void
    {
        $business = $this->business();
        $requirement = $this->requirement(null, [
            'status' => RequirementStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
        $match = $this->match($requirement, $business);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/requirements/{$requirement->uuid}/enquiries", ['match_id' => $match->uuid])
            ->assertStatus(422);
    }

    /* ------------------------------------------------------------------ *
     | Business decision
     * ------------------------------------------------------------------ */

    public function test_business_accepts_an_enquiry_and_unlocks_quoting(): void
    {
        $business = $this->business();
        $enquiry = $this->enquiry($this->requirement(), $business);

        $response = $this->actingAs($business->user, 'sanctum')->postJson(
            "/api/v1/enquiries/{$enquiry->uuid}/accept",
            ['message' => 'We can supply this.']
        );

        $response->assertOk();
        $response->assertJsonPath('data.attributes.status', EnquiryStatus::Accepted->value);
        $response->assertJsonPath('data.attributes.can_quote', true);
        $response->assertJsonPath('data.attributes.business_message', 'We can supply this.');
        $this->assertNotNull($enquiry->fresh()->accepted_at);
    }

    public function test_business_declines_an_enquiry(): void
    {
        $business = $this->business();
        $enquiry = $this->enquiry($this->requirement(), $business);

        $this->actingAs($business->user, 'sanctum')
            ->postJson("/api/v1/enquiries/{$enquiry->uuid}/decline", ['message' => 'Outside our capacity.'])
            ->assertOk()
            ->assertJsonPath('data.attributes.status', EnquiryStatus::Declined->value)
            ->assertJsonPath('data.attributes.can_quote', false);

        $this->assertNotNull($enquiry->fresh()->declined_at);
    }

    /** Accepting an enquiry also settles the Phase 4 match, so the two never disagree. */
    public function test_accepting_an_enquiry_settles_the_underlying_match(): void
    {
        $business = $this->business();
        $requirement = $this->requirement();
        $match = $this->match($requirement, $business);
        $enquiry = Enquiry::factory()->create([
            'requirement_id' => $requirement->id,
            'customer_id' => $requirement->customer_id,
            'business_id' => $business->id,
            'match_id' => $match->id,
        ]);

        $this->actingAs($business->user, 'sanctum')
            ->postJson("/api/v1/enquiries/{$enquiry->uuid}/accept")
            ->assertOk();

        $this->assertSame(MatchResponseStatus::Accepted, $match->fresh()->response_status);
    }

    /** And the reverse: accepting the match opens and accepts the enquiry. */
    public function test_accepting_a_match_opens_an_accepted_enquiry(): void
    {
        $business = $this->business();
        $requirement = $this->requirement();
        $match = $this->match($requirement, $business);

        $this->actingAs($business->user, 'sanctum')
            ->postJson("/api/v1/matches/{$match->uuid}/accept")
            ->assertOk();

        $this->assertDatabaseHas('enquiries', [
            'requirement_id' => $requirement->id,
            'business_id' => $business->id,
            'status' => EnquiryStatus::Accepted->value,
            'initiated_by' => 'business',
        ]);
    }

    public function test_declining_a_match_declines_the_enquiry(): void
    {
        $business = $this->business();
        $requirement = $this->requirement();
        $match = $this->match($requirement, $business);

        $this->actingAs($business->user, 'sanctum')
            ->postJson("/api/v1/matches/{$match->uuid}/decline", ['note' => 'Not our line of work.'])
            ->assertOk();

        $this->assertDatabaseHas('enquiries', [
            'requirement_id' => $requirement->id,
            'business_id' => $business->id,
            'status' => EnquiryStatus::Declined->value,
        ]);
    }

    public function test_business_cannot_respond_twice(): void
    {
        $business = $this->business();
        $enquiry = $this->enquiry($this->requirement(), $business);

        $this->actingAs($business->user, 'sanctum')->postJson("/api/v1/enquiries/{$enquiry->uuid}/accept")->assertOk();

        // Already answered: the policy's `awaitsBusinessResponse` gate closes.
        $this->actingAs($business->user, 'sanctum')
            ->postJson("/api/v1/enquiries/{$enquiry->uuid}/decline")
            ->assertForbidden();

        $this->assertSame(EnquiryStatus::Accepted, $enquiry->fresh()->status);
    }

    /* ------------------------------------------------------------------ *
     | Customer actions
     * ------------------------------------------------------------------ */

    public function test_customer_cancels_a_pending_enquiry(): void
    {
        $business = $this->business();
        $enquiry = $this->enquiry($this->requirement(), $business);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/enquiries/{$enquiry->uuid}/cancel")
            ->assertOk()
            ->assertJsonPath('data.attributes.status', EnquiryStatus::Cancelled->value);
    }

    public function test_customer_cannot_cancel_once_the_business_has_accepted(): void
    {
        $business = $this->business();
        $enquiry = $this->enquiry($this->requirement(), $business, [
            'status' => EnquiryStatus::Accepted,
            'accepted_at' => now(),
        ]);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/enquiries/{$enquiry->uuid}/cancel")
            ->assertStatus(422);
    }

    /** The customer must never be able to answer for the business. */
    public function test_customer_cannot_accept_or_decline_an_enquiry(): void
    {
        $business = $this->business();
        $enquiry = $this->enquiry($this->requirement(), $business);

        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/enquiries/{$enquiry->uuid}/accept")->assertForbidden();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/enquiries/{$enquiry->uuid}/decline")->assertForbidden();

        $this->assertSame(EnquiryStatus::Pending, $enquiry->fresh()->status);
    }

    public function test_either_party_can_close_an_enquiry(): void
    {
        $business = $this->business();
        $enquiry = $this->enquiry($this->requirement(), $business, [
            'status' => EnquiryStatus::Accepted,
            'accepted_at' => now(),
        ]);

        $this->actingAs($business->user, 'sanctum')
            ->postJson("/api/v1/enquiries/{$enquiry->uuid}/close")
            ->assertOk()
            ->assertJsonPath('data.attributes.status', EnquiryStatus::Closed->value);
    }

    /* ------------------------------------------------------------------ *
     | Visibility
     * ------------------------------------------------------------------ */

    public function test_customer_sees_only_their_own_enquiries(): void
    {
        $mine = $this->enquiry($this->requirement(), $this->business(), ['customer_message' => 'Mine']);
        $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $this->enquiry($this->requirement($otherCustomer), $this->business(), ['customer_message' => 'Theirs']);

        $response = $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/enquiries')->assertOk();

        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.id', $mine->uuid);
        $response->assertJsonMissing(['customer_message' => 'Theirs']);
    }

    public function test_business_sees_only_enquiries_addressed_to_it(): void
    {
        $mine = $this->business();
        $theirs = $this->business();

        $ours = $this->enquiry($this->requirement(), $mine);
        $this->enquiry($this->requirement(), $theirs);

        $response = $this->actingAs($mine->user, 'sanctum')->getJson('/api/v1/enquiries')->assertOk();

        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.id', $ours->uuid);
    }

    public function test_customer_cannot_view_another_customers_enquiry(): void
    {
        $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $enquiry = $this->enquiry($this->requirement($otherCustomer), $this->business());

        $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/enquiries/{$enquiry->uuid}")
            ->assertForbidden();
    }

    public function test_business_cannot_view_an_unrelated_enquiry(): void
    {
        $outsider = $this->business();
        $enquiry = $this->enquiry($this->requirement(), $this->business());

        $this->actingAs($outsider->user, 'sanctum')
            ->getJson("/api/v1/enquiries/{$enquiry->uuid}")
            ->assertForbidden();
    }

    public function test_business_cannot_respond_to_an_unrelated_enquiry(): void
    {
        $outsider = $this->business();
        $enquiry = $this->enquiry($this->requirement(), $this->business());

        $this->actingAs($outsider->user, 'sanctum')
            ->postJson("/api/v1/enquiries/{$enquiry->uuid}/accept")
            ->assertForbidden();

        $this->assertSame(EnquiryStatus::Pending, $enquiry->fresh()->status);
    }

    public function test_business_enquiry_does_not_expose_the_customer_identity(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Private Buyer']);
        $business = $this->business();
        $enquiry = $this->enquiry($this->requirement($customer), $business);

        $this->actingAs($business->user, 'sanctum')
            ->getJson("/api/v1/enquiries/{$enquiry->uuid}")
            ->assertOk()
            ->assertJsonMissing(['name' => 'Private Buyer']);
    }

    public function test_guest_cannot_access_enquiry_endpoints(): void
    {
        $enquiry = $this->enquiry($this->requirement(), $this->business());

        $this->getJson('/api/v1/enquiries')->assertUnauthorized();
        $this->getJson("/api/v1/enquiries/{$enquiry->uuid}")->assertUnauthorized();
        $this->postJson("/api/v1/enquiries/{$enquiry->uuid}/accept")->assertUnauthorized();
    }

    /** Admins may read for moderation. */
    public function test_admin_can_view_any_enquiry_but_cannot_respond(): void
    {
        $admin = User::factory()->admin()->create();
        $enquiry = $this->enquiry($this->requirement(), $this->business());

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/enquiries/{$enquiry->uuid}")->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/enquiries/{$enquiry->uuid}/accept")->assertForbidden();
    }

    public function test_owner_without_a_business_profile_sees_an_empty_list(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/enquiries')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_enquiries_can_be_filtered_by_status(): void
    {
        $business = $this->business();
        $this->enquiry($this->requirement(), $business, ['customer_message' => 'Pending one']);
        $this->enquiry($this->requirement(), $business, [
            'customer_message' => 'Accepted one',
            'status' => EnquiryStatus::Accepted,
            'accepted_at' => now(),
        ]);

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/enquiries?filter[status]=accepted')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['customer_message' => 'Accepted one'])
            ->assertJsonMissing(['customer_message' => 'Pending one']);
    }
}
