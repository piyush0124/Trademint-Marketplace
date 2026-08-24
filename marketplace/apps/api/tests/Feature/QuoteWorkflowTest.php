<?php

namespace Tests\Feature;

use App\Enums\EnquiryStatus;
use App\Enums\QuoteStatus;
use App\Enums\RequirementStatus;
use App\Enums\UserRole;
use App\Events\QuoteAccepted;
use App\Events\QuoteSent;
use App\Models\Business;
use App\Models\Category;
use App\Models\Enquiry;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Requirement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Phase 5 ??? the quotation half of the flow.
 *
 * Particular attention is paid to the things a hostile client would try:
 * sending its own totals, quoting an enquiry it does not own, editing a sent
 * quote, accepting an expired quote, accepting twice, and reading another
 * party's document.
 *
 * The industry-neutrality checks are deliberate: the same endpoints are
 * exercised with a physical product order, a service, and a project, with no
 * category-specific fields anywhere in the payloads.
 */
class QuoteWorkflowTest extends TestCase
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
        $this->enquiry = $this->makeEnquiry($this->business, EnquiryStatus::Accepted);
    }

    private function makeBusiness(): Business
    {
        return Business::factory()->create([
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
        ]);
    }

    private function makeRequirement(?User $customer = null): Requirement
    {
        return Requirement::factory()->create([
            'customer_id' => ($customer ?? $this->customer)->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'status' => RequirementStatus::Matching,
            'published_at' => now(),
        ]);
    }

    private function makeEnquiry(Business $business, EnquiryStatus $status = EnquiryStatus::Accepted, ?User $customer = null): Enquiry
    {
        $requirement = $this->makeRequirement($customer);

        return Enquiry::factory()->create([
            'requirement_id' => $requirement->id,
            'customer_id' => $requirement->customer_id,
            'business_id' => $business->id,
            'status' => $status,
            'accepted_at' => $status === EnquiryStatus::Accepted ? now() : null,
        ]);
    }

    /** A plain, valid payload. Overridable per test. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Supply and delivery',
            'description' => 'As discussed.',
            'validity_date' => now()->addDays(14)->toDateString(),
            'payment_terms' => '50% advance, balance on delivery.',
            'items' => [
                [
                    'description' => 'Line item',
                    'item_type' => 'product',
                    'quantity' => 10,
                    'unit' => 'units',
                    'unit_price' => 1000,
                    'tax_rate' => 18,
                ],
            ],
        ], $overrides);
    }

    private function createQuote(array $overrides = [], ?Enquiry $enquiry = null): Quote
    {
        $enquiry ??= $this->enquiry;

        $response = $this->actingAs($enquiry->business->user, 'sanctum')
            ->postJson("/api/v1/enquiries/{$enquiry->uuid}/quotes", $this->payload($overrides));

        $response->assertCreated();

        return Quote::where('uuid', $response->json('data.id'))->firstOrFail();
    }

    /* ------------------------------------------------------------------ *
     | Creating quotes
     * ------------------------------------------------------------------ */

    public function test_business_creates_a_draft_quote_on_an_accepted_enquiry(): void
    {
        $response = $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/enquiries/{$this->enquiry->uuid}/quotes", $this->payload());

        $response->assertCreated();
        $response->assertJsonPath('data.attributes.status', QuoteStatus::Draft->value);
        // 10 x 1000 = 10000, +18% tax.
        $response->assertJsonPath('data.attributes.subtotal', '10000.00');
        $response->assertJsonPath('data.attributes.tax_amount', '1800.00');
        $response->assertJsonPath('data.attributes.total_amount', '11800.00');
        $response->assertJsonPath('data.attributes.currency', config('marketplace.default_currency'));
        $response->assertJsonPath('data.relationships.items.0.attributes.total', '11800.00');

        $this->assertDatabaseHas('quotes', [
            'enquiry_id' => $this->enquiry->id,
            'customer_id' => $this->enquiry->customer_id,
            'business_id' => $this->business->id,
            'status' => QuoteStatus::Draft->value,
        ]);
    }

    /** Quoting is gated on acceptance ??? a pending enquiry is not quotable. */
    public function test_business_cannot_quote_an_enquiry_it_has_not_accepted(): void
    {
        $pending = $this->makeEnquiry($this->business, EnquiryStatus::Pending);

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/enquiries/{$pending->uuid}/quotes", $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_business_cannot_quote_another_businesss_enquiry(): void
    {
        $outsider = $this->makeBusiness();

        $this->actingAs($outsider->user, 'sanctum')
            ->postJson("/api/v1/enquiries/{$this->enquiry->uuid}/quotes", $this->payload())
            ->assertForbidden();
    }

    public function test_customer_cannot_create_a_quote(): void
    {
        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/enquiries/{$this->enquiry->uuid}/quotes", $this->payload())
            ->assertForbidden();
    }

    public function test_quote_number_is_generated_and_unique(): void
    {
        $first = $this->createQuote();
        $second = $this->createQuote();

        $this->assertMatchesRegularExpression('/^QT-\d{4}-\d{6}$/', $first->quote_number);
        $this->assertNotSame($first->quote_number, $second->quote_number);
        // Sequential within the year, and the year is the current one.
        $this->assertSame(sprintf('QT-%s-000001', now()->format('Y')), $first->quote_number);
        $this->assertSame(sprintf('QT-%s-000002', now()->format('Y')), $second->quote_number);
    }

    /* ------------------------------------------------------------------ *
     | Calculation integrity
     * ------------------------------------------------------------------ */

    /** The headline security property: client-supplied money is ignored. */
    public function test_client_supplied_totals_are_ignored(): void
    {
        $response = $this->actingAs($this->business->user, 'sanctum')->postJson(
            "/api/v1/enquiries/{$this->enquiry->uuid}/quotes",
            $this->payload([
                'subtotal' => 1,
                'tax_amount' => 0,
                'total_amount' => 1,
                'discount_amount' => 0,
                'items' => [[
                    'description' => 'Manipulated line',
                    'item_type' => 'product',
                    'quantity' => 4,
                    'unit_price' => 250,
                    'tax_rate' => 10,
                    // These three are derived; the request must not be able to set them.
                    'subtotal' => 1,
                    'tax_amount' => 0,
                    'total' => 1,
                ]],
            ])
        );

        $response->assertCreated();
        // 4 x 250 = 1000, +10% = 1100. Nothing the client sent survived.
        $response->assertJsonPath('data.attributes.subtotal', '1000.00');
        $response->assertJsonPath('data.attributes.tax_amount', '100.00');
        $response->assertJsonPath('data.attributes.total_amount', '1100.00');
        $response->assertJsonPath('data.relationships.items.0.attributes.subtotal', '1000.00');
        $response->assertJsonPath('data.relationships.items.0.attributes.total', '1100.00');
    }

    public function test_negative_prices_are_rejected(): void
    {
        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/enquiries/{$this->enquiry->uuid}/quotes", $this->payload([
                'items' => [['description' => 'Bad', 'item_type' => 'custom', 'quantity' => 1, 'unit_price' => -500]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.unit_price']);
    }

    public function test_zero_quantity_is_rejected(): void
    {
        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/enquiries/{$this->enquiry->uuid}/quotes", $this->payload([
                'items' => [['description' => 'Bad', 'item_type' => 'custom', 'quantity' => 0, 'unit_price' => 100]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity']);
    }

    public function test_a_discount_cannot_exceed_the_quote_total(): void
    {
        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/enquiries/{$this->enquiry->uuid}/quotes", $this->payload([
                'discount_amount' => 999999,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['discount_amount']);
    }

    public function test_a_quote_requires_at_least_one_item(): void
    {
        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/enquiries/{$this->enquiry->uuid}/quotes", $this->payload(['items' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    public function test_validity_date_in_the_past_is_rejected(): void
    {
        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/enquiries/{$this->enquiry->uuid}/quotes", $this->payload([
                'validity_date' => now()->subDay()->toDateString(),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['validity_date']);
    }

    /** A reference to another business's catalogue entry is dropped, not honoured. */
    public function test_catalogue_reference_from_another_business_is_not_stored(): void
    {
        $otherBusiness = $this->makeBusiness();
        $foreignProduct = Product::factory()->for($otherBusiness)->create(['category_id' => $this->category->id]);

        $quote = $this->createQuote([
            'items' => [[
                'description' => 'Line referencing a foreign product',
                'item_type' => 'product',
                'reference_id' => $foreignProduct->id,
                'quantity' => 1,
                'unit_price' => 500,
            ]],
        ]);

        $this->assertNull($quote->items->first()->reference_id);
    }

    public function test_catalogue_reference_from_own_business_is_stored(): void
    {
        $ownProduct = Product::factory()->for($this->business)->create(['category_id' => $this->category->id]);

        $quote = $this->createQuote([
            'items' => [[
                'description' => 'Line referencing own product',
                'item_type' => 'product',
                'reference_id' => $ownProduct->id,
                'quantity' => 1,
                'unit_price' => 500,
            ]],
        ]);

        $this->assertSame($ownProduct->id, (int) $quote->items->first()->reference_id);
    }

    /* ------------------------------------------------------------------ *
     | Industry neutrality
     * ------------------------------------------------------------------ */

    /**
     * The same endpoint, the same payload shape, four different industries.
     * Nothing category-specific appears anywhere ??? which is the architectural
     * requirement, not just a nicety.
     */
    public function test_the_same_quote_architecture_serves_every_industry(): void
    {
        $cases = [
            // [item_type, description, quantity, unit, unit_price, expected total]
            ['product', '500 units of steel', 500, 'kg', 800, '400000.00'],
            ['service', 'Digital marketing package', 1, 'month', 40000, '40000.00'],
            ['project', 'Warehouse construction', 1, null, 1200000, '1200000.00'],
            ['custom', 'Property legal consultation', 1, 'session', 10000, '10000.00'],
        ];

        foreach ($cases as [$type, $description, $quantity, $unit, $price, $expected]) {
            $enquiry = $this->makeEnquiry($this->business);

            $response = $this->actingAs($this->business->user, 'sanctum')->postJson(
                "/api/v1/enquiries/{$enquiry->uuid}/quotes",
                $this->payload([
                    'title' => $description,
                    'items' => [[
                        'description' => $description,
                        'item_type' => $type,
                        'quantity' => $quantity,
                        'unit' => $unit,
                        'unit_price' => $price,
                    ]],
                ])
            );

            $response->assertCreated();
            $response->assertJsonPath('data.attributes.total_amount', $expected);
            $response->assertJsonPath('data.relationships.items.0.attributes.item_type', $type);
        }
    }

    /** Businesses anywhere in India quote identically ??? no location logic in the engine. */
    public function test_quotes_work_for_businesses_in_any_indian_location(): void
    {
        $places = [
            ['city' => 'Coimbatore', 'state' => 'Tamil Nadu'],
            ['city' => 'Indore', 'state' => 'Madhya Pradesh'],
            ['city' => 'Guwahati', 'state' => 'Assam'],
            ['city' => 'Kolkata', 'state' => 'West Bengal'],
        ];

        foreach ($places as $place) {
            $location = Location::factory()->create([...$place, 'country' => 'IN']);
            $business = Business::factory()->create([
                'category_id' => $this->category->id,
                'location_id' => $location->id,
            ]);
            $enquiry = $this->makeEnquiry($business);

            $this->actingAs($business->user, 'sanctum')
                ->postJson("/api/v1/enquiries/{$enquiry->uuid}/quotes", $this->payload())
                ->assertCreated()
                ->assertJsonPath('data.attributes.total_amount', '11800.00');
        }
    }

    /* ------------------------------------------------------------------ *
     | Editing drafts
     * ------------------------------------------------------------------ */

    public function test_business_edits_its_own_draft_and_totals_are_recalculated(): void
    {
        $quote = $this->createQuote();

        $response = $this->actingAs($this->business->user, 'sanctum')->patchJson("/api/v1/quotes/{$quote->uuid}", [
            'title' => 'Revised scope',
            'delivery_fee' => 500,
            'items' => [
                ['description' => 'Revised line', 'item_type' => 'service', 'quantity' => 2, 'unit_price' => 2500, 'tax_rate' => 18],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.attributes.title', 'Revised scope');
        // 2 x 2500 = 5000, tax 900, delivery 500.
        $response->assertJsonPath('data.attributes.subtotal', '5000.00');
        $response->assertJsonPath('data.attributes.tax_amount', '900.00');
        $response->assertJsonPath('data.attributes.total_amount', '6400.00');
        // Items are replaced wholesale, not appended to.
        $this->assertCount(1, $quote->fresh()->items);
    }

    public function test_put_is_accepted_as_well_as_patch(): void
    {
        $quote = $this->createQuote();

        $this->actingAs($this->business->user, 'sanctum')
            ->putJson("/api/v1/quotes/{$quote->uuid}", ['title' => 'Updated via PUT'])
            ->assertOk()
            ->assertJsonPath('data.attributes.title', 'Updated via PUT');
    }

    public function test_business_cannot_edit_another_businesss_quote(): void
    {
        $outsider = $this->makeBusiness();
        $quote = $this->createQuote();

        $this->actingAs($outsider->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$quote->uuid}", ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->assertNotSame('Hijacked', $quote->fresh()->title);
    }

    public function test_customer_cannot_edit_a_quote(): void
    {
        $quote = $this->createQuote();
        $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/send")->assertOk();

        $this->actingAs($this->customer, 'sanctum')
            ->patchJson("/api/v1/quotes/{$quote->uuid}", ['title' => 'Cheaper please'])
            ->assertForbidden();
    }

    /** Once sent, the commercial content is frozen. */
    public function test_a_sent_quote_cannot_be_edited_or_deleted(): void
    {
        $quote = $this->createQuote();
        $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/send")->assertOk();

        $this->actingAs($this->business->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$quote->uuid}", ['title' => 'Sneaky change'])
            ->assertForbidden();

        $this->actingAs($this->business->user, 'sanctum')
            ->deleteJson("/api/v1/quotes/{$quote->uuid}")
            ->assertForbidden();

        $this->assertDatabaseHas('quotes', ['uuid' => $quote->uuid]);
    }

    public function test_business_deletes_its_own_draft(): void
    {
        $quote = $this->createQuote();

        $this->actingAs($this->business->user, 'sanctum')
            ->deleteJson("/api/v1/quotes/{$quote->uuid}")
            ->assertOk();

        $this->assertDatabaseMissing('quotes', ['uuid' => $quote->uuid]);
        $this->assertDatabaseCount('quote_items', 0);
    }

    /* ------------------------------------------------------------------ *
     | Sending and visibility
     * ------------------------------------------------------------------ */

    public function test_business_sends_a_quote(): void
    {
        Event::fake([QuoteSent::class]);
        $quote = $this->createQuote();

        $response = $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/send");

        $response->assertOk();
        $response->assertJsonPath('data.attributes.status', QuoteStatus::Sent->value);
        $this->assertNotNull($quote->fresh()->sent_at);
        // expires_at is derived from validity_date, not supplied by the client.
        $this->assertNotNull($quote->fresh()->expires_at);
        Event::assertDispatched(QuoteSent::class);
    }

    /** A draft is the business's private working document. */
    public function test_customer_cannot_see_a_draft_quote(): void
    {
        $quote = $this->createQuote();

        $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/quotes/{$quote->uuid}")
            ->assertForbidden();

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/quotes')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_customer_views_a_sent_quote_and_the_view_is_recorded(): void
    {
        $quote = $this->createQuote();
        $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/send")->assertOk();

        $response = $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/quotes/{$quote->uuid}");

        $response->assertOk();
        $response->assertJsonPath('data.attributes.status', QuoteStatus::Viewed->value);
        $response->assertJsonPath('data.attributes.can_decide', true);
        $response->assertJsonPath('data.relationships.items.0.attributes.description', 'Line item');
        $this->assertNotNull($quote->fresh()->viewed_at);
    }

    public function test_customer_cannot_view_another_customers_quote(): void
    {
        $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $otherEnquiry = $this->makeEnquiry($this->business, EnquiryStatus::Accepted, $otherCustomer);
        $quote = $this->createQuote([], $otherEnquiry);
        $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/send")->assertOk();

        $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/quotes/{$quote->uuid}")
            ->assertForbidden();
    }

    public function test_business_cannot_view_another_businesss_quote(): void
    {
        $outsider = $this->makeBusiness();
        $quote = $this->createQuote();

        $this->actingAs($outsider->user, 'sanctum')
            ->getJson("/api/v1/quotes/{$quote->uuid}")
            ->assertForbidden();
    }

    public function test_business_quote_list_contains_only_its_own_quotes(): void
    {
        $outsider = $this->makeBusiness();
        $outsiderEnquiry = $this->makeEnquiry($outsider);

        $mine = $this->createQuote(['title' => 'Mine']);
        $this->createQuote(['title' => 'Theirs'], $outsiderEnquiry);

        $response = $this->actingAs($this->business->user, 'sanctum')->getJson('/api/v1/quotes')->assertOk();

        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.id', $mine->uuid);
        $response->assertJsonMissing(['title' => 'Theirs']);
    }

    public function test_guest_cannot_access_quote_endpoints(): void
    {
        $quote = $this->createQuote();

        // createQuote() authenticated as the business owner, and actingAs
        // persists for the rest of the test ??? so the guard has to be cleared
        // before these can honestly be called guest requests.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/quotes')->assertUnauthorized();
        $this->getJson("/api/v1/quotes/{$quote->uuid}")->assertUnauthorized();
        $this->postJson("/api/v1/quotes/{$quote->uuid}/accept")->assertUnauthorized();
        $this->postJson("/api/v1/enquiries/{$this->enquiry->uuid}/quotes", $this->payload())->assertUnauthorized();
    }

    /* ------------------------------------------------------------------ *
     | Acceptance, rejection, withdrawal, expiry
     * ------------------------------------------------------------------ */

    private function sentQuote(): Quote
    {
        $quote = $this->createQuote();
        $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/send")->assertOk();

        return $quote->fresh();
    }

    public function test_customer_accepts_a_quote(): void
    {
        Event::fake([QuoteAccepted::class]);
        $quote = $this->sentQuote();

        $response = $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/accept");

        $response->assertOk();
        $response->assertJsonPath('data.attributes.status', QuoteStatus::Accepted->value);
        $this->assertNotNull($quote->fresh()->accepted_at);
        // The Phase 7 hand-off point fires exactly once.
        Event::assertDispatchedTimes(QuoteAccepted::class, 1);
    }

    /**
     * Acceptance is what makes a quote convertible into an order.
     *
     * This assertion changed with Phase 7. It previously asserted that no `orders`
     * table existed at all, which was the correct Phase 5 boundary; now that
     * orders exist, the meaningful invariant is that acceptance sets the
     * ready-for-order state and that conversion happens through the documented
     * QuoteAccepted hook rather than inside the quote flow. Order creation itself
     * is covered by OrderWorkflowTest.
     */
    public function test_accepting_a_quote_marks_it_ready_for_an_order(): void
    {
        $quote = $this->sentQuote();

        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/accept")->assertOk();

        $this->assertTrue($quote->fresh()->isReadyForOrder());
        // Exactly one order, created by the listener ??? never two.
        $this->assertSame(1, Order::where('quote_id', $quote->id)->count());
    }

    /** Double submission must not produce two acceptances. */
    public function test_a_quote_cannot_be_accepted_twice(): void
    {
        $quote = $this->sentQuote();

        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/accept")->assertOk();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/accept")->assertStatus(422);

        $this->assertSame(QuoteStatus::Accepted, $quote->fresh()->status);
    }

    public function test_customer_rejects_a_quote_with_a_reason(): void
    {
        $quote = $this->sentQuote();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/reject", ['reason' => 'Budget is lower than this.'])
            ->assertOk()
            ->assertJsonPath('data.attributes.status', QuoteStatus::Rejected->value)
            ->assertJsonPath('data.attributes.rejection_reason', 'Budget is lower than this.');

        // Rejected quotes are history, never deleted.
        $this->assertDatabaseHas('quotes', ['uuid' => $quote->uuid, 'status' => QuoteStatus::Rejected->value]);
    }

    public function test_a_rejected_quote_cannot_then_be_accepted(): void
    {
        $quote = $this->sentQuote();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/reject")->assertOk();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/accept")
            ->assertStatus(422);
    }

    public function test_business_cannot_accept_or_reject_its_own_quote(): void
    {
        $quote = $this->sentQuote();

        $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/accept")->assertForbidden();
        $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/reject")->assertForbidden();

        $this->assertSame(QuoteStatus::Sent, $quote->fresh()->status);
    }

    public function test_business_withdraws_a_sent_quote(): void
    {
        $quote = $this->sentQuote();

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.attributes.status', QuoteStatus::Withdrawn->value);

        // Withdrawn: the customer can no longer act on it.
        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/accept")
            ->assertStatus(422);
    }

    public function test_business_cannot_withdraw_an_accepted_quote(): void
    {
        $quote = $this->sentQuote();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/accept")->assertOk();

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/withdraw")
            ->assertStatus(422);
    }

    public function test_business_cannot_withdraw_another_businesss_quote(): void
    {
        $outsider = $this->makeBusiness();
        $quote = $this->sentQuote();

        $this->actingAs($outsider->user, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/withdraw")
            ->assertForbidden();
    }

    /** Expiry is enforced by the backend, not by a frontend date check. */
    public function test_an_expired_quote_cannot_be_accepted(): void
    {
        $quote = $this->sentQuote();
        // Simulate the validity window closing while the quote sat unread.
        $quote->forceFill([
            'validity_date' => now()->subDays(2)->toDateString(),
            'expires_at' => now()->subDay(),
        ])->save();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/accept")
            ->assertStatus(422);

        $this->assertSame(QuoteStatus::Expired, $quote->fresh()->status);
    }

    /** Reading a lapsed quote settles it, so the UI never offers an impossible action. */
    public function test_viewing_a_lapsed_quote_marks_it_expired(): void
    {
        $quote = $this->sentQuote();
        $quote->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/quotes/{$quote->uuid}")
            ->assertOk()
            ->assertJsonPath('data.attributes.status', QuoteStatus::Expired->value)
            ->assertJsonPath('data.attributes.is_expired', true)
            ->assertJsonPath('data.attributes.can_decide', false);
    }

    /** An expired quote may still be formally rejected ??? it just cannot be accepted. */
    public function test_an_expired_quote_can_still_be_rejected(): void
    {
        $quote = $this->sentQuote();
        $quote->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/reject")
            ->assertOk()
            ->assertJsonPath('data.attributes.status', QuoteStatus::Rejected->value);
    }

    public function test_expire_command_relabels_lapsed_quotes(): void
    {
        $quote = $this->sentQuote();
        $quote->forceFill(['expires_at' => now()->subDay()])->save();

        $this->artisan('quotes:expire')->assertSuccessful();

        $this->assertSame(QuoteStatus::Expired, $quote->fresh()->status);
    }

    /* ------------------------------------------------------------------ *
     | Enquiry <-> quote wiring
     * ------------------------------------------------------------------ */

    public function test_enquiry_quotes_endpoint_hides_drafts_from_the_customer(): void
    {
        $draft = $this->createQuote(['title' => 'Draft quote']);
        $sent = $this->createQuote(['title' => 'Sent quote']);
        $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/quotes/{$sent->uuid}/send")->assertOk();

        $businessView = $this->actingAs($this->business->user, 'sanctum')
            ->getJson("/api/v1/enquiries/{$this->enquiry->uuid}/quotes")
            ->assertOk();
        $this->assertSame(2, $businessView->json('meta.total'));

        $customerView = $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/enquiries/{$this->enquiry->uuid}/quotes")
            ->assertOk();
        $this->assertSame(1, $customerView->json('meta.total'));
        $customerView->assertJsonFragment(['title' => 'Sent quote']);
        $customerView->assertJsonMissing(['title' => 'Draft quote']);
        $this->assertNotNull($draft->fresh());
    }

    public function test_admin_can_view_a_quote_but_cannot_decide_on_it(): void
    {
        $admin = User::factory()->admin()->create();
        $quote = $this->sentQuote();

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/quotes/{$quote->uuid}")->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/accept")->assertForbidden();
        $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/quotes/{$quote->uuid}", ['title' => 'Admin edit'])->assertForbidden();
    }

    public function test_quote_list_can_be_filtered_by_status(): void
    {
        $this->createQuote(['title' => 'Still a draft']);
        $sent = $this->createQuote(['title' => 'Already sent']);
        $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/quotes/{$sent->uuid}/send")->assertOk();

        $this->actingAs($this->business->user, 'sanctum')
            ->getJson('/api/v1/quotes?filter[status]=sent')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['title' => 'Already sent'])
            ->assertJsonMissing(['title' => 'Still a draft']);
    }
}
