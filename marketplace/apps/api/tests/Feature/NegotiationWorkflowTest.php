<?php

namespace Tests\Feature;

use App\Enums\EnquiryStatus;
use App\Enums\NegotiationParty;
use App\Enums\NegotiationStatus;
use App\Enums\QuoteStatus;
use App\Enums\RequirementStatus;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Category;
use App\Models\Enquiry;
use App\Models\Location;
use App\Models\Negotiation;
use App\Models\NegotiationMessage;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\Requirement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 6 — negotiation, counter offers, revision immutability and the turn
 * discipline that makes invalid transitions unrepresentable.
 *
 * The scenarios below are the ones that actually matter commercially: countering
 * out of turn, accepting your own offer, countering after acceptance, two people
 * countering at once, and history being rewritten. All fixtures are factory
 * generated across arbitrary categories and locations.
 */
class NegotiationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Location $location;

    private User $customer;

    private Business $business;

    private Enquiry $enquiry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::factory()->create();
        $this->location = Location::factory()->create();
        $this->customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->business = $this->makeBusiness();
        $this->enquiry = $this->makeEnquiry($this->business);
    }

    private function makeBusiness(): Business
    {
        return Business::factory()->create([
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
        ]);
    }

    private function makeEnquiry(Business $business, ?User $customer = null): Enquiry
    {
        $requirement = Requirement::factory()->create([
            'customer_id' => ($customer ?? $this->customer)->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'status' => RequirementStatus::Matching,
            'published_at' => now(),
        ]);

        return Enquiry::factory()->create([
            'requirement_id' => $requirement->id,
            'customer_id' => $requirement->customer_id,
            'business_id' => $business->id,
            'status' => EnquiryStatus::Accepted,
            'accepted_at' => now(),
        ]);
    }

    private function quotePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Initial offer',
            'validity_date' => now()->addDays(14)->toDateString(),
            'items' => [
                ['description' => 'Widget', 'item_type' => 'product', 'quantity' => 100, 'unit' => 'piece', 'unit_price' => 1000, 'tax_rate' => 18],
            ],
        ], $overrides);
    }

    private function counterPayload(string $revisionId, array $overrides = []): array
    {
        return array_merge([
            'revision_id' => $revisionId,
            'message' => 'Can you do better on the unit price?',
            'items' => [
                ['description' => 'Widget', 'item_type' => 'product', 'quantity' => 100, 'unit' => 'piece', 'unit_price' => 850, 'tax_rate' => 18],
            ],
        ], $overrides);
    }

    /** A sent quote, plus the uuid of its opening revision. */
    private function sentQuote(?Enquiry $enquiry = null): array
    {
        $enquiry ??= $this->enquiry;
        $owner = $enquiry->business->user;

        $created = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/enquiries/{$enquiry->uuid}/quotes", $this->quotePayload())
            ->assertCreated();

        $quote = Quote::where('uuid', $created->json('data.id'))->firstOrFail();

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/send")->assertOk();

        $quote = $quote->fresh(['currentRevision']);

        return [$quote, $quote->currentRevision->uuid];
    }

    /* ------------------------------------------------------------------ *
     | Revision ledger
     * ------------------------------------------------------------------ */

    /** Sending a quote writes revision 1 — the opening offer, authored by the business. */
    public function test_sending_a_quote_records_the_opening_revision(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $this->assertDatabaseCount('quote_revisions', 1);
        $revision = QuoteRevision::where('uuid', $revisionId)->firstOrFail();

        $this->assertSame(1, (int) $revision->revision_number);
        $this->assertSame('initial', $revision->revision_type);
        $this->assertSame(NegotiationParty::Business, $revision->authored_by);
        $this->assertTrue($revision->is_current);
        // 100 x 1000 = 100000 + 18% tax.
        $this->assertSame('118000.00', $revision->total_amount);
        $this->assertSame($revision->total_amount, $quote->fresh()->total_amount);
        $this->assertCount(1, $revision->items);
    }

    /** The immutability guarantee, asserted rather than assumed. */
    public function test_a_written_revision_cannot_be_modified(): void
    {
        [, $revisionId] = $this->sentQuote();
        $revision = QuoteRevision::where('uuid', $revisionId)->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $revision->update(['total_amount' => 1]);
    }

    public function test_a_revision_cannot_be_deleted(): void
    {
        [, $revisionId] = $this->sentQuote();
        $revision = QuoteRevision::where('uuid', $revisionId)->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $revision->delete();
    }

    /** Countering appends; it never overwrites the previous commercial terms. */
    public function test_countering_appends_a_new_revision_and_preserves_the_old_one(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();

        $this->assertDatabaseCount('quote_revisions', 2);

        $original = QuoteRevision::where('uuid', $revisionId)->firstOrFail();
        $this->assertFalse($original->is_current);
        $this->assertNotNull($original->superseded_at);
        // The original figures survive untouched.
        $this->assertSame('118000.00', $original->total_amount);

        $current = $quote->fresh(['currentRevision'])->currentRevision;
        $this->assertSame(2, (int) $current->revision_number);
        $this->assertSame(NegotiationParty::Customer, $current->authored_by);
        // 100 x 850 = 85000 + 18% = 100300.
        $this->assertSame('100300.00', $current->total_amount);
    }

    /* ------------------------------------------------------------------ *
     | Counter offers and turn discipline
     * ------------------------------------------------------------------ */

    public function test_customer_can_counter_a_sent_quote(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId));

        $response->assertCreated();
        $response->assertJsonPath('data.attributes.status', NegotiationStatus::Countered->value);
        // The turn passes to the business.
        $response->assertJsonPath('data.attributes.awaiting_party', NegotiationParty::Business->value);
        $response->assertJsonPath('data.attributes.is_my_turn', false);
        $response->assertJsonPath('data.attributes.can_withdraw', true);
        $response->assertJsonPath('data.attributes.can_accept', false);

        $this->assertSame(QuoteStatus::Negotiating, $quote->fresh()->status);
    }

    public function test_business_can_counter_the_customers_counter(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $first = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();
        $customerRevision = $first->json('data.relationships.current_revision.id');

        $second = $this->actingAs($this->business->user, 'sanctum')->postJson(
            "/api/v1/quotes/{$quote->uuid}/counter",
            $this->counterPayload($customerRevision, [
                'message' => 'We can meet you at 920.',
                'is_final_offer' => true,
                'items' => [['description' => 'Widget', 'item_type' => 'product', 'quantity' => 100, 'unit' => 'piece', 'unit_price' => 920, 'tax_rate' => 18]],
            ])
        );

        $second->assertCreated();
        $second->assertJsonPath('data.attributes.awaiting_party', NegotiationParty::Customer->value);
        $second->assertJsonPath('data.attributes.round', 3);
        $second->assertJsonPath('data.relationships.current_revision.attributes.is_final_offer', true);

        $this->assertDatabaseCount('quote_revisions', 3);
    }

    /** You cannot counter your own offer — that is not a negotiation, it is a rewrite. */
    public function test_a_party_cannot_counter_its_own_offer(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        // The business made the opening offer, so it is not its turn.
        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertStatus(422);

        $this->assertDatabaseCount('quote_revisions', 1);
    }

    public function test_customer_cannot_counter_twice_in_a_row(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $first = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($first->json('data.relationships.current_revision.id')))
            ->assertStatus(422);

        $this->assertDatabaseCount('quote_revisions', 2);
    }

    /**
     * Optimistic concurrency: two counters racing on the same revision. The
     * second must be refused with 409 rather than silently applied on top.
     */
    public function test_a_counter_against_a_superseded_revision_is_refused(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();

        // The business responds, but against the *original* revision it had on screen.
        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertStatus(409);

        $this->assertDatabaseCount('quote_revisions', 2);
    }

    /** Counter offer money is recalculated server-side, exactly as in Phase 5. */
    public function test_counter_offer_totals_are_calculated_by_the_backend(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $response = $this->actingAs($this->customer, 'sanctum')->postJson(
            "/api/v1/quotes/{$quote->uuid}/counter",
            $this->counterPayload($revisionId, [
                // Bogus totals in the body must be ignored.
                'subtotal' => 1, 'tax_amount' => 0, 'total_amount' => 1,
                'items' => [[
                    'description' => 'Widget', 'item_type' => 'product', 'quantity' => 50, 'unit_price' => 900, 'tax_rate' => 10,
                    'subtotal' => 1, 'tax_amount' => 0, 'total' => 1,
                ]],
            ])
        );

        $response->assertCreated();
        // 50 x 900 = 45000, +10% = 49500.
        $response->assertJsonPath('data.relationships.current_revision.attributes.subtotal', '45000.00');
        $response->assertJsonPath('data.relationships.current_revision.attributes.tax_amount', '4500.00');
        $response->assertJsonPath('data.relationships.current_revision.attributes.total_amount', '49500.00');
    }

    public function test_counter_offer_rejects_negative_prices(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $this->actingAs($this->customer, 'sanctum')->postJson(
            "/api/v1/quotes/{$quote->uuid}/counter",
            $this->counterPayload($revisionId, [
                'items' => [['description' => 'Bad', 'item_type' => 'custom', 'quantity' => 1, 'unit_price' => -5]],
            ])
        )->assertStatus(422)->assertJsonValidationErrors(['items.0.unit_price']);
    }

    /* ------------------------------------------------------------------ *
     | Acceptance, rejection, withdrawal
     * ------------------------------------------------------------------ */

    public function test_business_accepts_the_customers_counter(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $countered = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();

        $negotiationId = $countered->json('data.id');
        $currentRevision = $countered->json('data.relationships.current_revision.id');

        $response = $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/negotiations/{$negotiationId}/accept", ['revision_id' => $currentRevision]);

        $response->assertOk();
        $response->assertJsonPath('data.attributes.status', NegotiationStatus::Accepted->value);
        $response->assertJsonPath('data.attributes.awaiting_party', null);

        // The quote settles at the accepted revision's terms.
        $fresh = $quote->fresh();
        $this->assertSame(QuoteStatus::Accepted, $fresh->status);
        $this->assertSame('100300.00', $fresh->total_amount);
        $this->assertNotNull($fresh->accepted_at);
        // The accepted revision is the hand-off point for Phase 7.
        $this->assertNotNull($fresh->acceptedRevision());
    }

    /** The explicit requirement: accepted → counter offer must not be allowed. */
    public function test_a_counter_offer_after_acceptance_is_refused(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $countered = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();
        $currentRevision = $countered->json('data.relationships.current_revision.id');

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/negotiations/{$countered->json('data.id')}/accept", ['revision_id' => $currentRevision])
            ->assertOk();

        // Either party trying to reopen it is refused.
        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($currentRevision))
            ->assertStatus(422);

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($currentRevision))
            ->assertStatus(422);

        $this->assertSame(NegotiationStatus::Accepted, Negotiation::firstOrFail()->status);
    }

    public function test_a_party_cannot_accept_its_own_offer(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $countered = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();

        // The customer authored the live offer, so it cannot also accept it.
        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/negotiations/{$countered->json('data.id')}/accept")
            ->assertStatus(422);

        $this->assertSame(NegotiationStatus::Countered, Negotiation::firstOrFail()->status);
    }

    /** Accepting twice must not produce two acceptances. */
    public function test_a_negotiation_cannot_be_accepted_twice(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $countered = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();
        $negotiationId = $countered->json('data.id');

        $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/negotiations/{$negotiationId}/accept")->assertOk();
        $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/negotiations/{$negotiationId}/accept")->assertStatus(422);
    }

    /** The customer may accept the live business offer through the Phase 5 route too. */
    public function test_customer_can_accept_the_businesss_counter_through_the_quote_endpoint(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $first = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();

        $this->actingAs($this->business->user, 'sanctum')->postJson(
            "/api/v1/quotes/{$quote->uuid}/counter",
            $this->counterPayload($first->json('data.relationships.current_revision.id'), [
                'items' => [['description' => 'Widget', 'item_type' => 'product', 'quantity' => 100, 'unit_price' => 920, 'tax_rate' => 18]],
            ])
        )->assertCreated();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/accept")
            ->assertOk()
            ->assertJsonPath('data.attributes.status', QuoteStatus::Accepted->value);

        $this->assertSame(NegotiationStatus::Accepted, Negotiation::firstOrFail()->status);
    }

    /** But not their own counter, even through the older route. */
    public function test_customer_cannot_accept_their_own_counter_through_the_quote_endpoint(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/accept")
            ->assertStatus(422);

        $this->assertSame(QuoteStatus::Negotiating, $quote->fresh()->status);
    }

    public function test_business_can_reject_the_customers_counter(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $countered = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/negotiations/{$countered->json('data.id')}/reject", ['note' => 'Below our cost.'])
            ->assertOk()
            ->assertJsonPath('data.attributes.status', NegotiationStatus::Rejected->value)
            ->assertJsonPath('data.attributes.outcome_note', 'Below our cost.');

        $this->assertSame(QuoteStatus::Rejected, $quote->fresh()->status);
        // History is kept, not deleted.
        $this->assertDatabaseCount('quote_revisions', 2);
    }

    public function test_the_offering_party_can_withdraw_its_live_offer(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $countered = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/negotiations/{$countered->json('data.id')}/withdraw", ['note' => 'Sourced elsewhere.'])
            ->assertOk()
            ->assertJsonPath('data.attributes.status', NegotiationStatus::Withdrawn->value);

        $this->assertSame(QuoteStatus::Withdrawn, $quote->fresh()->status);
    }

    /** You cannot withdraw an offer that is not yours. */
    public function test_a_party_cannot_withdraw_the_other_sides_offer(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $countered = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/negotiations/{$countered->json('data.id')}/withdraw")
            ->assertStatus(422);

        $this->assertSame(NegotiationStatus::Countered, Negotiation::firstOrFail()->status);
    }

    /* ------------------------------------------------------------------ *
     | Expiry
     * ------------------------------------------------------------------ */

    public function test_an_expired_offer_cannot_be_accepted_or_countered(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $countered = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId, [
                'validity_date' => now()->addDay()->toDateString(),
            ]))
            ->assertCreated();

        $negotiation = Negotiation::firstOrFail();
        // Force the live offer past its validity window.
        DB::table('quote_revisions')
            ->where('id', $negotiation->current_revision_id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/negotiations/{$countered->json('data.id')}/accept")
            ->assertStatus(422);

        $this->assertSame(NegotiationStatus::Expired, $negotiation->fresh()->status);
    }

    /** Reading a negotiation settles a lapsed offer, so the UI cannot offer "Accept". */
    public function test_viewing_a_lapsed_negotiation_marks_it_expired(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $countered = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();

        DB::table('quote_revisions')
            ->where('id', Negotiation::firstOrFail()->current_revision_id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($this->business->user, 'sanctum')
            ->getJson("/api/v1/negotiations/{$countered->json('data.id')}")
            ->assertOk()
            ->assertJsonPath('data.attributes.status', NegotiationStatus::Expired->value)
            ->assertJsonPath('data.attributes.can_accept', false);
    }

    /* ------------------------------------------------------------------ *
     | Timeline
     * ------------------------------------------------------------------ */

    public function test_the_timeline_records_every_commercial_action(): void
    {
        [$quote, $revisionId] = $this->sentQuote();

        $countered = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();
        $negotiationId = $countered->json('data.id');

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/negotiations/{$negotiationId}/messages", ['body' => 'Checking with production.'])
            ->assertCreated();

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/negotiations/{$negotiationId}/accept")
            ->assertOk();

        $timeline = $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/negotiations/{$negotiationId}")
            ->assertOk()
            ->json('data.relationships.messages');

        $types = array_column(array_column($timeline, 'attributes'), 'entry_type');
        $this->assertSame(['counter', 'message', 'accept'], $types);
        // The timeline never reveals who the customer is, only which side spoke.
        $this->assertSame(['customer', 'business', 'business'], array_column(array_column($timeline, 'attributes'), 'author_party'));
    }

    public function test_timeline_entries_are_append_only(): void
    {
        [$quote, $revisionId] = $this->sentQuote();
        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();

        $entry = NegotiationMessage::firstOrFail();

        $this->expectException(\RuntimeException::class);
        $entry->update(['body' => 'Rewritten history']);
    }

    /* ------------------------------------------------------------------ *
     | Authorization
     * ------------------------------------------------------------------ */

    public function test_a_stranger_cannot_counter_a_quote(): void
    {
        [$quote, $revisionId] = $this->sentQuote();
        $outsider = $this->makeBusiness();

        $this->actingAs($outsider->user, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertForbidden();

        $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($otherCustomer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertForbidden();

        $this->assertDatabaseCount('quote_revisions', 1);
    }

    public function test_a_stranger_cannot_read_a_negotiation(): void
    {
        [$quote, $revisionId] = $this->sentQuote();
        $countered = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();
        $negotiationId = $countered->json('data.id');

        $outsider = $this->makeBusiness();
        $this->actingAs($outsider->user, 'sanctum')->getJson("/api/v1/negotiations/{$negotiationId}")->assertForbidden();

        $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($otherCustomer, 'sanctum')->getJson("/api/v1/negotiations/{$negotiationId}")->assertForbidden();
    }

    public function test_admin_can_read_but_not_act_in_a_negotiation(): void
    {
        [$quote, $revisionId] = $this->sentQuote();
        $countered = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();
        $negotiationId = $countered->json('data.id');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/negotiations/{$negotiationId}")->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/negotiations/{$negotiationId}/accept")->assertForbidden();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/negotiations/{$negotiationId}/reject")->assertForbidden();
    }

    public function test_guest_cannot_touch_negotiation_endpoints(): void
    {
        [$quote, $revisionId] = $this->sentQuote();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/negotiations')->assertUnauthorized();
        $this->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))->assertUnauthorized();
    }

    public function test_negotiation_list_is_scoped_to_the_caller(): void
    {
        [$quote, $revisionId] = $this->sentQuote();
        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/counter", $this->counterPayload($revisionId))
            ->assertCreated();

        // A second, unrelated negotiation.
        $otherBusiness = $this->makeBusiness();
        $otherEnquiry = $this->makeEnquiry($otherBusiness, User::factory()->create(['role' => UserRole::Customer]));
        [$otherQuote, $otherRevision] = $this->sentQuote($otherEnquiry);
        $this->actingAs($otherEnquiry->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$otherQuote->uuid}/counter", $this->counterPayload($otherRevision))
            ->assertCreated();

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/negotiations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($this->business->user, 'sanctum')
            ->getJson('/api/v1/negotiations?filter[awaiting_me]=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }
}
