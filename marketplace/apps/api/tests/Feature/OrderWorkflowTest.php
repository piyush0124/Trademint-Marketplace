<?php

namespace Tests\Feature;

use App\Enums\EnquiryStatus;
use App\Enums\FulfilmentStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\RequirementStatus;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Category;
use App\Models\Enquiry;
use App\Models\Location;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Requirement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 7 — order creation from an accepted quote, the lifecycle state machine,
 * the immutable status history, and access control.
 *
 * The order pipeline is exercised with product, service and project lines to prove
 * it is not a product-only design.
 */
class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Location $location;

    private User $customer;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::factory()->create();
        $this->location = Location::factory()->create();
        $this->customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->business = $this->makeBusiness();
    }

    private function makeBusiness(): Business
    {
        return Business::factory()->create([
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
        ]);
    }

    private function makeEnquiry(?Business $business = null, ?User $customer = null): Enquiry
    {
        $business ??= $this->business;
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

    /** Accepted quote → order, the whole way through the real endpoints. */
    private function acceptedQuote(?Enquiry $enquiry = null, array $items = []): Quote
    {
        $enquiry ??= $this->makeEnquiry();
        $owner = $enquiry->business->user;
        $customer = $enquiry->customer;

        $created = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/enquiries/{$enquiry->uuid}/quotes", [
            'title' => 'Agreed scope',
            'validity_date' => now()->addDays(10)->toDateString(),
            'estimated_delivery_date' => now()->addDays(20)->toDateString(),
            'payment_terms' => '50% advance.',
            'delivery_fee' => 2000,
            'items' => $items !== [] ? $items : [
                ['description' => 'Widget', 'item_type' => 'product', 'quantity' => 10, 'unit' => 'piece', 'unit_price' => 1000, 'tax_rate' => 18],
            ],
        ])->assertCreated();

        $quote = Quote::where('uuid', $created->json('data.id'))->firstOrFail();

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/send")->assertOk();
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/accept")->assertOk();

        return $quote->fresh();
    }

    /* ------------------------------------------------------------------ *
     | Creation
     * ------------------------------------------------------------------ */

    /** The Phase 5 hand-off, now wired: accepting a quote produces an order. */
    public function test_accepting_a_quote_creates_an_order_automatically(): void
    {
        $quote = $this->acceptedQuote();

        $order = Order::firstOrFail();

        $this->assertSame($quote->id, $order->quote_id);
        // Traceable to the exact immutable revision that was agreed.
        $this->assertSame($quote->current_revision_id, $order->quote_revision_id);
        $this->assertSame($quote->customer_id, $order->customer_id);
        $this->assertSame($quote->business_id, $order->business_id);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->payment_status);
        $this->assertSame(FulfilmentStatus::NotStarted, $order->fulfilment_status);
        $this->assertNotNull($order->placed_at);
    }

    /** Totals are copied from the agreed revision, never recomputed or trusted. */
    public function test_order_totals_match_the_accepted_quote(): void
    {
        $quote = $this->acceptedQuote();
        $order = Order::firstOrFail();

        // 10 x 1000 = 10000, +18% = 11800, +2000 delivery = 13800.
        $this->assertSame('10000.00', $order->subtotal);
        $this->assertSame('1800.00', $order->tax_amount);
        $this->assertSame('2000.00', $order->delivery_fee);
        $this->assertSame('13800.00', $order->total_amount);
        $this->assertSame($quote->total_amount, $order->total_amount);
        $this->assertSame($quote->currency, $order->currency);
        $this->assertSame('13800.00', $order->amountDue());
    }

    public function test_order_number_is_unique_and_human_readable(): void
    {
        $this->acceptedQuote();
        $this->acceptedQuote($this->makeEnquiry());

        $numbers = Order::pluck('order_number');

        $this->assertCount(2, $numbers);
        $this->assertSame($numbers->unique()->count(), $numbers->count());
        $this->assertMatchesRegularExpression('/^ORD-\d{4}-\d{6}$/', $numbers->first());
        $this->assertSame(sprintf('ORD-%s-000001', now()->format('Y')), $numbers->first());
    }

    /** One accepted quote, one order — even if creation is retried. */
    public function test_order_creation_is_idempotent(): void
    {
        $quote = $this->acceptedQuote();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/order")
            ->assertCreated();
        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/order")
            ->assertCreated();

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_an_unaccepted_quote_cannot_become_an_order(): void
    {
        $enquiry = $this->makeEnquiry();
        $owner = $enquiry->business->user;

        $created = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/enquiries/{$enquiry->uuid}/quotes", [
            'title' => 'Not accepted yet',
            'items' => [['description' => 'Item', 'item_type' => 'service', 'quantity' => 1, 'unit_price' => 5000]],
        ])->assertCreated();

        $quote = Quote::where('uuid', $created->json('data.id'))->firstOrFail();
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/send")->assertOk();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote->uuid}/order")
            ->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    /** Order lines are copies of the agreed terms and cannot be altered. */
    public function test_order_items_are_copied_and_immutable(): void
    {
        $this->acceptedQuote(null, [
            ['description' => '500 units of steel', 'item_type' => 'product', 'quantity' => 500, 'unit' => 'kg', 'unit_price' => 800, 'tax_rate' => 18],
            ['description' => 'Website development', 'item_type' => 'service', 'quantity' => 1, 'unit_price' => 150000],
            ['description' => 'Warehouse construction', 'item_type' => 'project', 'quantity' => 1, 'unit_price' => 1200000],
        ]);

        $order = Order::firstOrFail();
        $this->assertCount(3, $order->items);
        $this->assertSame(['product', 'service', 'project'], $order->items->pluck('item_type.value')->all());

        $this->expectException(\RuntimeException::class);
        $order->items->first()->update(['unit_price' => 1]);
    }

    /** No industry assumptions: the same order shape serves goods, services, projects. */
    public function test_orders_support_every_industry_shape(): void
    {
        $cases = [
            ['product', 'Office chairs', 500, 'piece', 900],
            ['service', 'Annual maintenance contract', 12, 'month', 15000],
            ['project', 'Office interior fit-out', 1, null, 850000],
            ['custom', 'Property legal consultation', 1, 'session', 10000],
        ];

        foreach ($cases as [$type, $description, $quantity, $unit, $price]) {
            $this->acceptedQuote($this->makeEnquiry(), [
                ['description' => $description, 'item_type' => $type, 'quantity' => $quantity, 'unit' => $unit, 'unit_price' => $price],
            ]);
        }

        $this->assertDatabaseCount('orders', 4);
        $this->assertSame(4, Order::whereNotNull('order_number')->count());
    }

    /* ------------------------------------------------------------------ *
     | Lifecycle
     * ------------------------------------------------------------------ */

    public function test_business_confirms_and_progresses_an_order(): void
    {
        $this->acceptedQuote();
        $order = Order::firstOrFail();
        $owner = $this->business->user;

        $confirmed = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'confirmed', 'note' => 'Materials in stock.'])
            ->assertOk();

        $confirmed->assertJsonPath('data.attributes.status', OrderStatus::Confirmed->value);
        $this->assertNotNull($order->fresh()->confirmed_at);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.attributes.fulfilment_status', FulfilmentStatus::InProgress->value);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('data.attributes.fulfilment_status', FulfilmentStatus::Delivered->value);
    }

    public function test_customer_confirms_completion(): void
    {
        $this->acceptedQuote();
        $order = Order::firstOrFail();

        $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'confirmed'])->assertOk();
        $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'delivered'])->assertOk();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.attributes.status', OrderStatus::Completed->value);

        $this->assertNotNull($order->fresh()->completed_at);
    }

    /** A business cannot sign off its own work as complete. */
    public function test_business_cannot_mark_an_order_completed(): void
    {
        $this->acceptedQuote();
        $order = Order::firstOrFail();
        $owner = $this->business->user;

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'confirmed'])->assertOk();
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'delivered'])->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'completed'])
            ->assertStatus(422);

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_illegal_transitions_are_refused(): void
    {
        $this->acceptedQuote();
        $order = Order::firstOrFail();

        // pending → delivered skips confirmation, which the map disallows.
        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'delivered'])
            ->assertStatus(422);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_a_completed_order_cannot_be_reopened(): void
    {
        $this->acceptedQuote();
        $order = Order::firstOrFail();
        $owner = $this->business->user;

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'confirmed'])->assertOk();
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'delivered'])->assertOk();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'completed'])->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'in_progress'])
            ->assertStatus(422);
    }

    public function test_customer_can_cancel_only_before_confirmation(): void
    {
        $this->acceptedQuote();
        $order = Order::firstOrFail();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'cancelled', 'note' => 'No longer needed.'])
            ->assertOk()
            ->assertJsonPath('data.attributes.status', OrderStatus::Cancelled->value);

        $this->assertSame('No longer needed.', $order->fresh()->cancellation_reason);
    }

    public function test_customer_cannot_cancel_once_work_has_started(): void
    {
        $this->acceptedQuote();
        $order = Order::firstOrFail();
        $owner = $this->business->user;

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'confirmed'])->assertOk();
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'in_progress'])->assertOk();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'cancelled'])
            ->assertStatus(422);

        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
    }

    /** Two simultaneous transitions must not both apply. */
    public function test_repeating_the_same_transition_is_refused(): void
    {
        $this->acceptedQuote();
        $order = Order::firstOrFail();
        $owner = $this->business->user;

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'confirmed'])->assertOk();
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'confirmed'])->assertStatus(422);

        $this->assertSame(1, $order->fresh()->statusHistory()->where('to_status', 'confirmed')->count());
    }

    /* ------------------------------------------------------------------ *
     | History
     * ------------------------------------------------------------------ */

    public function test_every_status_change_is_recorded_immutably(): void
    {
        $this->acceptedQuote();
        $order = Order::firstOrFail();
        $owner = $this->business->user;

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'confirmed', 'note' => 'Confirmed by production.'])->assertOk();
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'in_progress'])->assertOk();

        $history = $order->fresh()->statusHistory;

        // Creation + two transitions.
        $this->assertCount(3, $history);
        $this->assertSame([null, 'pending', 'confirmed'], $history->pluck('from_status')->map(fn ($status) => $status?->value)->all());
        $this->assertSame(['pending', 'confirmed', 'in_progress'], $history->pluck('to_status.value')->all());
        $this->assertSame(['system', 'business', 'business'], $history->pluck('changed_by_party')->all());
        $this->assertSame('Confirmed by production.', $history[1]->note);

        $this->expectException(\RuntimeException::class);
        $history->first()->update(['note' => 'Rewritten']);
    }

    public function test_history_cannot_be_deleted(): void
    {
        $this->acceptedQuote();
        $entry = Order::firstOrFail()->statusHistory()->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $entry->delete();
    }

    /* ------------------------------------------------------------------ *
     | Access control
     * ------------------------------------------------------------------ */

    public function test_customer_sees_only_their_own_orders(): void
    {
        $this->acceptedQuote();
        $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $this->acceptedQuote($this->makeEnquiry($this->makeBusiness(), $otherCustomer));

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_business_sees_only_its_own_orders(): void
    {
        $this->acceptedQuote();
        $otherBusiness = $this->makeBusiness();
        $this->acceptedQuote($this->makeEnquiry($otherBusiness));

        $this->actingAs($this->business->user, 'sanctum')
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_a_stranger_cannot_read_or_change_an_order(): void
    {
        $this->acceptedQuote();
        $order = Order::firstOrFail();

        $outsider = $this->makeBusiness();
        $this->actingAs($outsider->user, 'sanctum')->getJson("/api/v1/orders/{$order->uuid}")->assertForbidden();
        $this->actingAs($outsider->user, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'confirmed'])
            ->assertForbidden();

        $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($otherCustomer, 'sanctum')->getJson("/api/v1/orders/{$order->uuid}")->assertForbidden();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_guest_cannot_access_order_endpoints(): void
    {
        $this->acceptedQuote();
        $order = Order::firstOrFail();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/orders')->assertUnauthorized();
        $this->getJson("/api/v1/orders/{$order->uuid}")->assertUnauthorized();
        $this->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'confirmed'])->assertUnauthorized();
    }

    /** Admins may read and resolve, and the action is attributed to them. */
    public function test_admin_can_view_and_resolve_but_the_action_is_audited(): void
    {
        $this->acceptedQuote();
        $order = Order::firstOrFail();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/orders/{$order->uuid}")->assertOk();
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/status", ['status' => 'cancelled', 'note' => 'Cancelled after support call.'])
            ->assertOk();

        // The relation is ordered oldest-first, so take the last entry rather than
        // re-sorting the query (an extra orderBy would not override the relation's).
        $entry = $order->fresh()->statusHistory->last();
        $this->assertSame('admin', $entry->changed_by_party);
        $this->assertSame($admin->id, $entry->changed_by_user_id);
    }

    public function test_each_party_keeps_its_own_notes(): void
    {
        $this->acceptedQuote();
        $order = Order::firstOrFail();

        $this->actingAs($this->customer, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->uuid}/notes", ['note' => 'Deliver to gate 2.'])
            ->assertOk()
            ->assertJsonPath('data.attributes.customer_notes', 'Deliver to gate 2.');

        $this->actingAs($this->business->user, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->uuid}/notes", ['note' => 'Dispatch scheduled Friday.'])
            ->assertOk()
            ->assertJsonPath('data.attributes.business_notes', 'Dispatch scheduled Friday.');

        // Neither overwrote the other.
        $fresh = $order->fresh();
        $this->assertSame('Deliver to gate 2.', $fresh->customer_notes);
        $this->assertSame('Dispatch scheduled Friday.', $fresh->business_notes);
    }

    /** The API tells each side what it may do, matching what the service allows. */
    public function test_available_transitions_reflect_the_viewers_party(): void
    {
        $this->acceptedQuote();
        $order = Order::firstOrFail();

        $businessView = $this->actingAs($this->business->user, 'sanctum')
            ->getJson("/api/v1/orders/{$order->uuid}")
            ->assertOk();
        $businessTargets = array_column($businessView->json('data.attributes.available_transitions'), 'value');
        $this->assertContains('confirmed', $businessTargets);
        $this->assertNotContains('completed', $businessTargets);

        $customerView = $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/orders/{$order->uuid}")
            ->assertOk();
        $customerTargets = array_column($customerView->json('data.attributes.available_transitions'), 'value');
        $this->assertContains('cancelled', $customerTargets);
        $this->assertNotContains('confirmed', $customerTargets);
    }

    /** An order accepted after a negotiation carries the negotiated figures. */
    public function test_an_order_from_a_negotiated_quote_uses_the_agreed_revision(): void
    {
        $enquiry = $this->makeEnquiry();
        $owner = $enquiry->business->user;

        $created = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/enquiries/{$enquiry->uuid}/quotes", [
            'title' => 'Opening offer',
            'validity_date' => now()->addDays(10)->toDateString(),
            'items' => [['description' => 'Widget', 'item_type' => 'product', 'quantity' => 100, 'unit_price' => 1000]],
        ])->assertCreated();

        $quote = Quote::where('uuid', $created->json('data.id'))->firstOrFail();
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/send")->assertOk();

        $revisionId = $quote->fresh()->currentRevision->uuid;

        // Customer counters down to 850/unit; business accepts.
        $countered = $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/counter", [
            'revision_id' => $revisionId,
            'items' => [['description' => 'Widget', 'item_type' => 'product', 'quantity' => 100, 'unit_price' => 850]],
        ])->assertCreated();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/negotiations/{$countered->json('data.id')}/accept")
            ->assertOk();

        $order = Order::firstOrFail();
        // The negotiated total, not the opening one.
        $this->assertSame('85000.00', $order->total_amount);
        $this->assertSame($quote->fresh()->current_revision_id, $order->quote_revision_id);
    }
}
