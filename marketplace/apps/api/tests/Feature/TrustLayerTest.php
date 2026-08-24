<?php

namespace Tests\Feature;

use App\Enums\EnquiryStatus;
use App\Enums\OrderStatus;
use App\Enums\RequirementStatus;
use App\Enums\ReviewStatus;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\Category;
use App\Models\Enquiry;
use App\Models\Location;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Requirement;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 9 — verification workflow, business passport, trust score and reviews.
 *
 * The review tests concentrate on eligibility, because that is where review systems
 * are gamed: reviewing without trading, reviewing twice, reviewing yourself, and a
 * business trying to bury criticism.
 */
class TrustLayerTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Location $location;

    private User $customer;

    private Business $business;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::factory()->create();
        $this->location = Location::factory()->create();
        $this->customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->business = $this->makeBusiness();
        $this->admin = User::factory()->admin()->create();
    }

    private function makeBusiness(): Business
    {
        return Business::factory()->create([
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
        ]);
    }

    /** A completed order — the only thing that earns a review. */
    private function completedOrder(?Business $business = null, ?User $customer = null): Order
    {
        $business ??= $this->business;
        $customer ??= $this->customer;

        $requirement = Requirement::factory()->create([
            'customer_id' => $customer->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'status' => RequirementStatus::Matching,
            'published_at' => now(),
        ]);

        $enquiry = Enquiry::factory()->create([
            'requirement_id' => $requirement->id,
            'customer_id' => $customer->id,
            'business_id' => $business->id,
            'status' => EnquiryStatus::Accepted,
            'accepted_at' => now(),
        ]);

        $created = $this->actingAs($business->user, 'sanctum')->postJson("/api/v1/enquiries/{$enquiry->uuid}/quotes", [
            'title' => 'Work',
            'validity_date' => now()->addDays(10)->toDateString(),
            'items' => [['description' => 'Item', 'item_type' => 'service', 'quantity' => 1, 'unit_price' => 5000]],
        ])->assertCreated();

        $quote = Quote::where('uuid', $created->json('data.id'))->firstOrFail();
        $this->actingAs($business->user, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/send")->assertOk();
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/accept")->assertOk();

        $order = Order::where('quote_id', $quote->id)->firstOrFail();

        $this->actingAs($business->user, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'confirmed'])->assertOk();
        $this->actingAs($business->user, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'delivered'])->assertOk();
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'completed'])->assertOk();

        return $order->fresh();
    }

    /* ------------------------------------------------------------------ *
     | Verification workflow
     * ------------------------------------------------------------------ */

    public function test_a_business_cannot_verify_itself(): void
    {
        // No self-service verification endpoint exists; the admin route is guarded.
        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/admin/businesses/{$this->business->id}/verification", ['action' => 'approve'])
            ->assertForbidden();

        $this->assertSame(VerificationStatus::Unverified, $this->business->fresh()->verificationStatus());
        $this->assertFalse((bool) $this->business->fresh()->is_verified);
    }

    /**
     * Regression: `GET businesses/verification` was being shadowed by the public
     * `GET businesses/{slug}` route, which is declared earlier, so it 404'd as
     * "no business with the slug 'verification'". The POST twin was unaffected,
     * which is why the original tests missed it. This pins the GET specifically.
     */
    public function test_owner_can_read_their_own_verification_state(): void
    {
        $response = $this->actingAs($this->business->user, 'sanctum')
            ->getJson('/api/v1/businesses/verification')
            ->assertOk();

        $response->assertJsonPath('data.status', VerificationStatus::Unverified->value);
        $response->assertJsonPath('data.can_request', true);
        // The upload form is driven by the API, not a hardcoded frontend list.
        $this->assertSame(BusinessDocument::TYPES, $response->json('data.document_types'));
        $this->assertSame([], $response->json('data.documents'));

        // Not a public endpoint, and not another business's business.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/businesses/verification')->assertUnauthorized();

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/businesses/verification')
            ->assertNotFound();
    }

    public function test_verification_requires_a_document_before_it_can_be_requested(): void
    {
        $this->actingAs($this->business->user, 'sanctum')
            ->postJson('/api/v1/businesses/verification')
            ->assertStatus(422);

        $this->assertSame(VerificationStatus::Unverified, $this->business->fresh()->verificationStatus());
    }

    public function test_business_uploads_a_document_and_requests_verification(): void
    {
        Storage::fake('local');

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson('/api/v1/businesses/documents', [
                'type' => 'registration',
                'title' => 'Certificate of incorporation',
                'file' => UploadedFile::fake()->create('registration.pdf', 20, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', BusinessDocument::STATUS_PENDING);

        $document = BusinessDocument::firstOrFail();
        // Stored on the private disk, under a generated name.
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertStringStartsWith('business-documents/', $document->file_path);

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson('/api/v1/businesses/verification')
            ->assertOk()
            ->assertJsonPath('data.status', VerificationStatus::Pending->value);
    }

    public function test_document_upload_rejects_disallowed_file_types(): void
    {
        Storage::fake('local');

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson('/api/v1/businesses/documents', [
                'type' => 'registration',
                'file' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        $this->assertDatabaseCount('business_documents', 0);
    }

    public function test_admin_approves_verification_and_the_action_is_audited(): void
    {
        Storage::fake('local');
        $this->uploadDocument();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/businesses/{$this->business->id}/verification", ['action' => 'approve', 'reason' => 'Documents verified.'])
            ->assertOk();

        $fresh = $this->business->fresh();
        $this->assertSame(VerificationStatus::Verified, $fresh->verificationStatus());
        $this->assertTrue((bool) $fresh->is_verified);
        $this->assertNotNull($fresh->verified_at);
        $this->assertSame($this->admin->id, $fresh->verified_by_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'business.verification.approve',
            'user_id' => $this->admin->id,
            'entity_type' => 'Business',
        ]);
    }

    public function test_admin_rejection_requires_a_reason(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/businesses/{$this->business->id}/verification", ['action' => 'reject'])
            ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/businesses/{$this->business->id}/verification", ['action' => 'reject', 'reason' => 'Documents unreadable.'])
            ->assertOk();

        $this->assertSame(VerificationStatus::Rejected, $this->business->fresh()->verificationStatus());
    }

    /** Suspension removes a business from discovery without stranding its orders. */
    public function test_admin_suspends_a_business_and_it_leaves_discovery(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/businesses/{$this->business->id}/verification", ['action' => 'suspend', 'reason' => 'Fraud investigation.'])
            ->assertOk();

        $fresh = $this->business->fresh();
        $this->assertSame(VerificationStatus::Suspended, $fresh->verificationStatus());
        $this->assertFalse((bool) $fresh->is_available);

        $this->getJson('/api/v1/businesses')
            ->assertOk()
            ->assertJsonMissing(['slug' => $this->business->slug]);
    }

    public function test_a_document_is_only_downloadable_by_its_owner_or_an_admin(): void
    {
        Storage::fake('local');
        $document = $this->uploadDocument();

        $this->actingAs($this->business->user, 'sanctum')->get("/api/v1/business-documents/{$document->uuid}/download")->assertOk();
        $this->actingAs($this->admin, 'sanctum')->get("/api/v1/business-documents/{$document->uuid}/download")->assertOk();

        $outsider = $this->makeBusiness();
        $this->actingAs($outsider->user, 'sanctum')->getJson("/api/v1/business-documents/{$document->uuid}/download")->assertForbidden();
        $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/business-documents/{$document->uuid}/download")->assertForbidden();
    }

    private function uploadDocument(): BusinessDocument
    {
        $this->actingAs($this->business->user, 'sanctum')
            ->postJson('/api/v1/businesses/documents', [
                'type' => 'gst',
                'file' => UploadedFile::fake()->create('gst.pdf', 10, 'application/pdf'),
            ])
            ->assertCreated();

        return BusinessDocument::firstOrFail();
    }

    /* ------------------------------------------------------------------ *
     | Reviews
     * ------------------------------------------------------------------ */

    public function test_customer_reviews_a_completed_order(): void
    {
        $order = $this->completedOrder();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/review", ['rating' => 5, 'comment' => 'Excellent work, delivered early.'])
            ->assertCreated()
            ->assertJsonPath('data.attributes.rating', 5)
            ->assertJsonPath('data.attributes.status', ReviewStatus::Published->value);

        // The business rating is recomputed, not incremented.
        $fresh = $this->business->fresh();
        $this->assertSame('5.00', $fresh->rating);
        $this->assertSame(1, (int) $fresh->review_count);
    }

    /** No trade, no review. This is the core anti-manipulation rule. */
    public function test_an_order_that_is_not_completed_cannot_be_reviewed(): void
    {
        $requirement = Requirement::factory()->create([
            'customer_id' => $this->customer->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'status' => RequirementStatus::Matching,
            'published_at' => now(),
        ]);
        $enquiry = Enquiry::factory()->create([
            'requirement_id' => $requirement->id,
            'customer_id' => $this->customer->id,
            'business_id' => $this->business->id,
            'status' => EnquiryStatus::Accepted,
            'accepted_at' => now(),
        ]);
        $created = $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/enquiries/{$enquiry->uuid}/quotes", [
            'title' => 'Work', 'validity_date' => now()->addDays(5)->toDateString(),
            'items' => [['description' => 'Item', 'item_type' => 'service', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated();
        $quote = Quote::where('uuid', $created->json('data.id'))->firstOrFail();
        $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/send")->assertOk();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/accept")->assertOk();

        $order = Order::where('quote_id', $quote->id)->firstOrFail();
        $this->assertSame(OrderStatus::Pending, $order->status);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/review", ['rating' => 1])
            ->assertStatus(422);

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_an_order_can_only_be_reviewed_once(): void
    {
        $order = $this->completedOrder();

        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/review", ['rating' => 4])->assertCreated();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/review", ['rating' => 1])->assertStatus(422);

        $this->assertDatabaseCount('reviews', 1);
        $this->assertSame('4.00', $this->business->fresh()->rating);
    }

    public function test_another_customer_cannot_review_someone_elses_order(): void
    {
        $order = $this->completedOrder();
        $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);

        $this->actingAs($otherCustomer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/review", ['rating' => 1])
            ->assertForbidden();

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_a_business_cannot_review_its_own_order(): void
    {
        $order = $this->completedOrder();

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/review", ['rating' => 5])
            ->assertForbidden();
    }

    public function test_rating_must_be_between_one_and_five(): void
    {
        $order = $this->completedOrder();

        foreach ([0, 6, -1] as $rating) {
            $this->actingAs($this->customer, 'sanctum')
                ->postJson("/api/v1/orders/{$order->uuid}/review", ['rating' => $rating])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['rating']);
        }
    }

    /** A submitted review is immutable — the model enforces it, not just the API. */
    public function test_a_review_cannot_be_edited_or_deleted(): void
    {
        $order = $this->completedOrder();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/review", ['rating' => 2, 'comment' => 'Late.'])->assertCreated();

        $review = Review::firstOrFail();

        try {
            $review->update(['rating' => 5]);
            $this->fail('A review rating should not be editable.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cannot be altered', $exception->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $review->delete();
    }

    public function test_business_responds_to_a_review_once(): void
    {
        $order = $this->completedOrder();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/review", ['rating' => 3, 'comment' => 'Mixed.'])->assertCreated();
        $review = Review::firstOrFail();

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/reviews/{$review->uuid}/response", ['response' => 'Thank you — we have addressed the delay.'])
            ->assertOk()
            ->assertJsonPath('data.attributes.business_response', 'Thank you — we have addressed the delay.');

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/reviews/{$review->uuid}/response", ['response' => 'Second attempt.'])
            ->assertForbidden();
    }

    /** A business cannot bury a bad review; only an admin can hide one. */
    public function test_a_business_cannot_moderate_a_review_about_itself(): void
    {
        $order = $this->completedOrder();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/review", ['rating' => 1, 'comment' => 'Poor.'])->assertCreated();
        $review = Review::firstOrFail();

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/admin/reviews/{$review->uuid}/moderate", ['status' => 'hidden'])
            ->assertForbidden();

        $this->assertSame(ReviewStatus::Published, $review->fresh()->status);
        $this->assertSame('1.00', $this->business->fresh()->rating);
    }

    public function test_admin_hides_a_review_and_the_rating_is_recomputed(): void
    {
        $order = $this->completedOrder();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/review", ['rating' => 1, 'comment' => 'Abusive content.'])->assertCreated();
        $review = Review::firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/reviews/{$review->uuid}/moderate", ['status' => 'hidden', 'note' => 'Violates guidelines.'])
            ->assertOk();

        $fresh = $review->fresh();
        $this->assertSame(ReviewStatus::Hidden, $fresh->status);
        // Hidden, not deleted — and no longer counted.
        $this->assertDatabaseCount('reviews', 1);
        $this->assertNull($this->business->fresh()->rating);
        $this->assertSame(0, (int) $this->business->fresh()->review_count);
    }

    public function test_public_reviews_do_not_expose_customer_identity(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Priya Sharma', 'email' => 'priya.private@example.test']);
        $order = $this->completedOrder(null, $customer);
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/review", ['rating' => 5])->assertCreated();

        $response = $this->getJson("/api/v1/businesses/{$this->business->slug}/reviews")->assertOk();

        // First name only; no email, no full name.
        $response->assertJsonPath('data.0.attributes.reviewer_name', 'Priya');
        $this->assertStringNotContainsString('priya.private@example.test', $response->getContent());
        $this->assertStringNotContainsString('Sharma', $response->getContent());
    }

    /* ------------------------------------------------------------------ *
     | Passport + trust score
     * ------------------------------------------------------------------ */

    public function test_passport_is_public_and_reports_only_real_data(): void
    {
        $response = $this->getJson("/api/v1/businesses/{$this->business->slug}/passport")->assertOk();

        $attributes = $response->json('data.attributes');

        $this->assertSame(VerificationStatus::Unverified->value, $attributes['verification_status']);
        // A brand new business has no rating, no orders and no response history —
        // reported as null/zero, never invented.
        $this->assertNull($attributes['rating']);
        $this->assertSame(0, $attributes['completed_orders']);
        $this->assertNull($attributes['response_rate']);
        $this->assertNull($attributes['response_time_hours']);
        $this->assertIsArray($attributes['trust_factors']);
        $this->assertNotEmpty($attributes['trust_factors']);
    }

    /** Unavailable factors are excluded from the score rather than counted as zero. */
    public function test_trust_factors_are_transparent_and_marked_unavailable_when_unproven(): void
    {
        $response = $this->getJson("/api/v1/businesses/{$this->business->slug}/passport")->assertOk();

        $factors = collect($response->json('data.attributes.trust_factors'))->keyBy('key');

        $this->assertTrue($factors['verification']['available']);
        $this->assertFalse($factors['completed_orders']['available']);
        $this->assertFalse($factors['review_quality']['available']);
        $this->assertSame('No completed orders yet', $factors['completed_orders']['evidence']);
        // Every factor carries its configured weight, so the score is explainable.
        // Compared loosely because JSON renders 25.0 as 25.
        $this->assertEquals(config('marketplace.trust.weights.verification'), $factors['verification']['weight']);
    }

    public function test_trust_score_improves_with_verification_and_reviews(): void
    {
        $before = $this->getJson("/api/v1/businesses/{$this->business->slug}/passport")->json('data.attributes.trust_score');

        $order = $this->completedOrder();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/review", ['rating' => 5])->assertCreated();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/businesses/{$this->business->id}/verification", ['action' => 'approve'])
            ->assertOk();

        $after = $this->getJson("/api/v1/businesses/{$this->business->slug}/passport")->json('data.attributes.trust_score');

        $this->assertGreaterThan($before, $after);
    }

    /** The score is configuration-driven, not hardcoded. */
    public function test_trust_weights_are_configurable(): void
    {
        config()->set('marketplace.trust.weights', [
            'verification' => 100,
            'profile_completeness' => 0,
            'completed_orders' => 0,
            'review_quality' => 0,
            'responsiveness' => 0,
            'reliability' => 0,
            'account_age' => 0,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/businesses/{$this->business->id}/verification", ['action' => 'approve'])
            ->assertOk();

        $score = $this->getJson("/api/v1/businesses/{$this->business->slug}/passport")->json('data.attributes.trust_score');

        // Verified but not fully verified across every dimension = 0.8 of the only
        // weighted factor.
        $this->assertSame(80, $score);
    }

    /**
     * The passport is public, so this checks the *values* that must never appear in
     * it — a real GST number, a document path — rather than key names, which is the
     * assertion that would actually catch a leak.
     */
    public function test_passport_never_exposes_documents_or_private_fields(): void
    {
        Storage::fake('local');
        $document = $this->uploadDocument();
        $this->business->forceFill(['gst_number' => '22AAAAA0000A1Z5'])->save();

        // uploadDocument() authenticated as the owner, and the owner *is* entitled
        // to see their own GST number. Clear the guard so this genuinely tests the
        // public view.
        $this->app['auth']->forgetGuards();

        $response = $this->getJson("/api/v1/businesses/{$this->business->slug}/passport")->assertOk();
        $body = $response->getContent();

        // The document's storage path is never disclosed, and no URL to it exists.
        $this->assertStringNotContainsString($document->file_path, $body);
        // The GST number itself is sensitive government ID data.
        $this->assertStringNotContainsString('22AAAAA0000A1Z5', $body);
        // Only aggregate document status is published.
        $response->assertJsonPath('data.attributes.document_status.pending', 1);
        $response->assertJsonPath('data.attributes.document_status.approved', 0);
    }

    /* ------------------------------------------------------------------ *
     | Discovery (Phase 10 filters)
     * ------------------------------------------------------------------ */

    public function test_discovery_filters_by_verification_rating_and_location(): void
    {
        $verified = $this->makeBusiness();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/businesses/{$verified->id}/verification", ['action' => 'approve'])
            ->assertOk();

        $this->getJson('/api/v1/businesses?filter[verified]=1')
            ->assertOk()
            ->assertJsonFragment(['slug' => $verified->slug])
            ->assertJsonMissing(['slug' => $this->business->slug]);

        // Location filtering is text-based against the database — works for any city.
        $this->getJson('/api/v1/businesses?filter[city]='.urlencode($this->location->city))
            ->assertOk()
            ->assertJsonFragment(['slug' => $verified->slug]);

        $this->getJson('/api/v1/businesses?filter[city]=Nowhere-Ville-XYZ')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    /** Pan-India: the same filter works for any state, with no special cases. */
    public function test_discovery_works_for_any_indian_location(): void
    {
        foreach ([['Kochi', 'Kerala'], ['Jaipur', 'Rajasthan'], ['Guwahati', 'Assam']] as [$city, $state]) {
            $location = Location::factory()->create(['city' => $city, 'state' => $state, 'country' => 'IN']);
            $business = Business::factory()->create(['category_id' => $this->category->id, 'location_id' => $location->id]);

            $this->getJson('/api/v1/businesses?filter[city]='.urlencode($city))
                ->assertOk()
                ->assertJsonFragment(['slug' => $business->slug]);

            $this->getJson('/api/v1/businesses?filter[state]='.urlencode($state))
                ->assertOk()
                ->assertJsonFragment(['slug' => $business->slug]);
        }
    }

    public function test_discovery_finds_a_business_through_its_subcategory_catalogue(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);

        $specialist = Business::factory()->create(['category_id' => $child->id, 'location_id' => $this->location->id]);

        // Searching the parent industry finds the subcategory specialist.
        $this->getJson('/api/v1/businesses?filter[category]='.$parent->slug)
            ->assertOk()
            ->assertJsonFragment(['slug' => $specialist->slug]);
    }
}
