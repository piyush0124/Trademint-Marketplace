<?php

namespace Tests\Feature;

use App\Enums\DisputeStatus;
use App\Enums\EnquiryStatus;
use App\Enums\OrderStatus;
use App\Enums\RequirementStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Category;
use App\Models\Dispute;
use App\Models\Enquiry;
use App\Models\Location;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Requirement;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 10 — disputes, the audit trail, and admin moderation surfaces.
 *
 * The emphasis is on the two properties that make an admin layer trustworthy: staff
 * actions are recorded and attributable, and staff cannot act commercially on a
 * party's behalf.
 */
class DisputeAndAuditTest extends TestCase
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

    private function order(?Business $business = null, ?User $customer = null): Order
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
            'items' => [['description' => 'Item', 'item_type' => 'project', 'quantity' => 1, 'unit_price' => 25000]],
        ])->assertCreated();

        $quote = Quote::where('uuid', $created->json('data.id'))->firstOrFail();
        $this->actingAs($business->user, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/send")->assertOk();
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/accept")->assertOk();

        $order = Order::where('quote_id', $quote->id)->firstOrFail();
        $this->actingAs($business->user, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'confirmed'])->assertOk();
        $this->actingAs($business->user, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'in_progress'])->assertOk();

        return $order->fresh();
    }

    /* ------------------------------------------------------------------ *
     | Disputes
     * ------------------------------------------------------------------ */

    public function test_customer_raises_a_dispute_and_the_order_reflects_it(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/disputes", [
            'reason' => 'late',
            'description' => 'The delivery date has passed with no update from the supplier.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.attributes.status', DisputeStatus::Open->value);
        $response->assertJsonPath('data.attributes.raised_by_party', 'customer');
        $this->assertMatchesRegularExpression('/^DSP-\d{4}-\d{6}$/', $response->json('data.attributes.reference'));

        // The order moves to disputed, through the guarded transition, so the
        // status history records it like any other change.
        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Disputed, $fresh->status);
        $this->assertSame('disputed', $fresh->statusHistory->last()->to_status->value);
    }

    public function test_business_can_also_raise_a_dispute(): void
    {
        $order = $this->order();

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/disputes", [
                'reason' => 'billing',
                'description' => 'The customer has not settled the agreed advance payment.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.attributes.raised_by_party', 'business');
    }

    public function test_a_stranger_cannot_raise_a_dispute(): void
    {
        $order = $this->order();
        $outsider = $this->makeBusiness();
        $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);

        $this->actingAs($outsider->user, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/disputes", ['reason' => 'quality', 'description' => 'Meddling in another order.'])
            ->assertForbidden();

        $this->actingAs($otherCustomer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/disputes", ['reason' => 'quality', 'description' => 'Meddling in another order.'])
            ->assertForbidden();

        $this->assertDatabaseCount('disputes', 0);
    }

    public function test_only_one_active_dispute_per_order(): void
    {
        $order = $this->order();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/disputes", ['reason' => 'late', 'description' => 'First complaint about the delay.'])
            ->assertCreated();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/disputes", ['reason' => 'quality', 'description' => 'Second complaint while the first is open.'])
            ->assertStatus(422);

        $this->assertDatabaseCount('disputes', 1);
    }

    public function test_dispute_description_is_validated(): void
    {
        $order = $this->order();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/disputes", ['reason' => 'not_a_real_reason', 'description' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason', 'description']);
    }

    /** Neither party can resolve its own dispute. */
    public function test_parties_cannot_resolve_their_own_dispute(): void
    {
        $order = $this->order();
        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/disputes", ['reason' => 'late', 'description' => 'Delivery is overdue by two weeks.'])
            ->assertCreated();

        $dispute = Dispute::firstOrFail();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/admin/disputes/{$dispute->uuid}/status", ['status' => 'resolved'])
            ->assertForbidden();

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/admin/disputes/{$dispute->uuid}/status", ['status' => 'rejected'])
            ->assertForbidden();

        $this->assertSame(DisputeStatus::Open, $dispute->fresh()->status);
    }

    public function test_admin_reviews_and_resolves_a_dispute(): void
    {
        $order = $this->order();
        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/disputes", ['reason' => 'quality', 'description' => 'The finish does not match the agreed specification.'])
            ->assertCreated();

        $dispute = Dispute::firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/disputes/{$dispute->uuid}/status", ['status' => 'under_review', 'admin_notes' => 'Requested photographs.'])
            ->assertOk()
            ->assertJsonPath('data.attributes.status', DisputeStatus::UnderReview->value);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/disputes/{$dispute->uuid}/status", [
                'status' => 'resolved',
                'resolution' => 'Business agreed to rework at no charge.',
            ])
            ->assertOk()
            ->assertJsonPath('data.attributes.status', DisputeStatus::Resolved->value);

        $fresh = $dispute->fresh();
        $this->assertNotNull($fresh->resolved_at);
        $this->assertSame($this->admin->id, $fresh->resolved_by_user_id);
    }

    public function test_a_resolved_dispute_cannot_be_reopened(): void
    {
        $order = $this->order();
        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/disputes", ['reason' => 'late', 'description' => 'Overdue by three weeks now.'])
            ->assertCreated();
        $dispute = Dispute::firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/disputes/{$dispute->uuid}/status", ['status' => 'resolved', 'resolution' => 'Settled.'])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/disputes/{$dispute->uuid}/status", ['status' => 'open'])
            ->assertStatus(422);
    }

    /** The complaint as filed is a record; it cannot be rewritten. */
    public function test_a_raised_dispute_cannot_be_rewritten(): void
    {
        $order = $this->order();
        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/disputes", ['reason' => 'quality', 'description' => 'Original complaint text.'])
            ->assertCreated();

        $dispute = Dispute::firstOrFail();

        $this->expectException(\RuntimeException::class);
        $dispute->update(['description' => 'Rewritten complaint.']);
    }

    public function test_disputes_are_scoped_to_their_parties(): void
    {
        $order = $this->order();
        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/disputes", ['reason' => 'late', 'description' => 'Our dispute about the delay.'])
            ->assertCreated();

        $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $otherBusiness = $this->makeBusiness();
        $otherOrder = $this->order($otherBusiness, $otherCustomer);
        $this->actingAs($otherCustomer, 'sanctum')
            ->postJson("/api/v1/orders/{$otherOrder->uuid}/disputes", ['reason' => 'quality', 'description' => 'A different dispute entirely.'])
            ->assertCreated();

        $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/disputes')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($this->business->user, 'sanctum')->getJson('/api/v1/disputes')->assertOk()->assertJsonPath('meta.total', 1);
        // The admin queue sees both.
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/disputes')->assertOk()->assertJsonPath('meta.total', 2);
    }

    /** Internal admin notes are not shown to the parties. */
    public function test_admin_notes_are_not_visible_to_the_parties(): void
    {
        $order = $this->order();
        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/disputes", ['reason' => 'late', 'description' => 'Overdue delivery.'])
            ->assertCreated();
        $dispute = Dispute::firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/disputes/{$dispute->uuid}/status", ['status' => 'under_review', 'admin_notes' => 'Internal: supplier has prior complaints.'])
            ->assertOk();

        $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/disputes/{$dispute->uuid}")
            ->assertOk()
            ->assertJsonPath('data.attributes.admin_notes', null);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/disputes/{$dispute->uuid}")
            ->assertOk()
            ->assertJsonPath('data.attributes.admin_notes', 'Internal: supplier has prior complaints.');
    }

    /* ------------------------------------------------------------------ *
     | Audit log
     * ------------------------------------------------------------------ */

    public function test_admin_actions_are_audited_with_actor_and_entity(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/businesses/{$this->business->id}/verification", ['action' => 'suspend', 'reason' => 'Under investigation.'])
            ->assertOk();

        $log = AuditLog::where('action', 'business.verification.suspend')->firstOrFail();

        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('admin', $log->actor_role);
        $this->assertSame('Business', $log->entity_type);
        $this->assertSame($this->business->id, (int) $log->entity_id);
        $this->assertSame($this->business->slug, $log->entity_reference);
        $this->assertSame('Under investigation.', $log->metadata['reason']);
    }

    public function test_audit_entries_are_append_only(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/businesses/{$this->business->id}/verification", ['action' => 'approve'])
            ->assertOk();

        $log = AuditLog::firstOrFail();

        try {
            $log->update(['action' => 'tampered']);
            $this->fail('Audit entries must not be editable.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $log->delete();
    }

    /** Secrets must never reach the audit metadata, even if a caller passes them. */
    public function test_audit_metadata_drops_sensitive_keys(): void
    {
        $logger = app(AuditLogger::class);

        $log = $logger->log('test.action', $this->business, $this->admin, [
            'reason' => 'legitimate context',
            'password' => 'should-not-be-stored',
            'card_number' => '4111111111111111',
            'api_token' => 'secret-token',
            'nested' => ['not' => 'scalar'],
        ]);

        $metadata = $log->fresh()->metadata;

        $this->assertSame('legitimate context', $metadata['reason']);
        $this->assertArrayNotHasKey('password', $metadata);
        $this->assertArrayNotHasKey('card_number', $metadata);
        $this->assertArrayNotHasKey('api_token', $metadata);
        // Non-scalar values are dropped so the log cannot become a data dump.
        $this->assertArrayNotHasKey('nested', $metadata);
    }

    public function test_only_admins_can_read_the_audit_log(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/audit-logs')->assertOk();
        $this->actingAs($this->business->user, 'sanctum')->getJson('/api/v1/admin/audit-logs')->assertForbidden();
        $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/admin/audit-logs')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/audit-logs')->assertUnauthorized();
    }

    /* ------------------------------------------------------------------ *
     | Admin moderation surfaces
     * ------------------------------------------------------------------ */

    public function test_admin_dashboard_reports_platform_counts(): void
    {
        $this->order();

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/dashboard')->assertOk();

        $response->assertJsonPath('data.marketplace.orders', 1);
        $response->assertJsonPath('data.businesses.total', 1);
        $this->assertIsInt($response->json('data.attention.open_disputes'));
        $this->assertIsInt($response->json('data.users.total'));
    }

    public function test_admin_monitoring_endpoints_are_admin_only(): void
    {
        $this->order();

        foreach (['users', 'businesses', 'orders', 'payments', 'reviews', 'disputes', 'audit-logs', 'dashboard'] as $endpoint) {
            $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/admin/$endpoint")->assertOk();
            $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/admin/$endpoint")->assertForbidden();
            $this->actingAs($this->business->user, 'sanctum')->getJson("/api/v1/admin/$endpoint")->assertForbidden();
        }
    }

    /**
     * The boundary on admin power: staff may moderate, but may not act commercially
     * for either party. Verified here across the whole pipeline in one place.
     */
    public function test_admins_cannot_act_commercially_for_either_party(): void
    {
        $order = $this->order();
        $quote = $order->quote;

        // Cannot accept or reject a quote on the customer's behalf.
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/accept")->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/reject")->assertForbidden();
        // Cannot pay an order.
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/payments", ['provider' => 'manual'])->assertForbidden();
        // Cannot review a business.
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/review", ['rating' => 5])->assertForbidden();
        // Cannot counter a quote.
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/counter", [
            'revision_id' => $quote->fresh()->currentRevision->uuid,
            'items' => [['description' => 'x', 'item_type' => 'custom', 'quantity' => 1, 'unit_price' => 1]],
        ])->assertForbidden();
    }

    public function test_admin_user_and_business_lists_support_filtering(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/users?filter[role]=customer')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/businesses?filter[verification_status]=unverified')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/businesses')
            ->assertOk()
            ->assertJsonPath('meta.verification_counts.unverified', 1);
    }

    /* ------------------------------------------------------------------ *
     | Order affordances
     |
     | The order API advertises what each viewer may do (can_review, can_dispute)
     | so the UI never has to reimplement the eligibility rules. These tests pin
     | that contract: if the rules move, the advertised flags must move with them.
     * ------------------------------------------------------------------ */

    public function test_order_advertises_dispute_affordance_and_the_open_dispute(): void
    {
        $order = $this->order();

        $before = $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/orders/{$order->uuid}")->assertOk();
        $before->assertJsonPath('data.attributes.can_dispute', true);
        $before->assertJsonPath('data.attributes.can_review', false);
        $before->assertJsonPath('data.relationships.active_dispute', null);
        // The reason list is served by the API, so no frontend copy can drift.
        $this->assertContains('late', $before->json('data.attributes.dispute_reasons'));

        $reference = $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/disputes", [
            'reason' => 'late',
            'description' => 'Two weeks past the agreed delivery date with no update.',
        ])->assertCreated()->json('data.attributes.reference');

        // One dispute at a time, for either party — and both can see it.
        foreach ([$this->customer, $this->business->user] as $viewer) {
            $after = $this->actingAs($viewer, 'sanctum')->getJson("/api/v1/orders/{$order->uuid}")->assertOk();
            $after->assertJsonPath('data.attributes.can_dispute', false);
            $after->assertJsonPath('data.relationships.active_dispute.reference', $reference);
            $after->assertJsonPath('data.relationships.active_dispute.raised_by_party', 'customer');
        }
    }

    public function test_order_advertises_review_eligibility_exactly_once(): void
    {
        $order = $this->order();

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'delivered'])
            ->assertOk();

        // Not completed yet: the customer is told why, rather than shown nothing.
        $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.attributes.can_review', false)
            ->assertJsonPath('data.attributes.review_blocked_reason', 'You can review a business once the order is completed.');

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'completed'])
            ->assertOk();

        $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.attributes.can_review', true)
            ->assertJsonPath('data.attributes.review_blocked_reason', null);

        // The business never sees a review affordance on its own order.
        $this->actingAs($this->business->user, 'sanctum')
            ->getJson("/api/v1/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.attributes.can_review', false)
            ->assertJsonPath('data.attributes.review_blocked_reason', null);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/review", ['rating' => 5, 'comment' => 'Delivered as agreed.'])
            ->assertCreated();

        $after = $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/orders/{$order->uuid}")->assertOk();
        $after->assertJsonPath('data.attributes.can_review', false);
        $after->assertJsonPath('data.attributes.review_blocked_reason', 'You have already reviewed this order.');
        $after->assertJsonPath('data.relationships.review.attributes.rating', 5);
    }
}
