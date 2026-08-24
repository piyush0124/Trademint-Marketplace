<?php

namespace Tests\Feature;

use App\Enums\NegotiationParty;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Events\EnquiryAccepted;
use App\Events\NegotiationCountered;
use App\Events\OrderCompleted;
use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Events\QuoteAccepted;
use App\Events\QuoteExpired;
use App\Events\QuoteSent;
use App\Events\QuoteViewed;
use App\Models\Business;
use App\Models\Enquiry;
use App\Models\Negotiation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\Requirement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Coverage for the notification layer.
 *
 * The tests are organised around the routing rule rather than around the classes,
 * because "was the right person told" is the only question that matters here and it
 * is the one a class-per-event structure makes hard to see.
 */
class NotificationLayerTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->business = Business::factory()->create(['user_id' => $this->owner->id]);

        Cache::flush();
    }

    private function requirement(): Requirement
    {
        return Requirement::factory()->create([
            'customer_id' => $this->customer->id,
            'title' => 'Need 200 ergonomic office chairs for our Pune office',
        ]);
    }

    private function quote(): Quote
    {
        return Quote::factory()->create([
            'customer_id' => $this->customer->id,
            'business_id' => $this->business->id,
            'requirement_id' => $this->requirement()->id,
        ]);
    }

    private function order(): Order
    {
        return Order::factory()->create([
            'customer_id' => $this->customer->id,
            'business_id' => $this->business->id,
        ]);
    }

    /**
     * Built by hand because there is no PaymentFactory in this codebase, and adding
     * one purely for this test would be a wider change than the test justifies.
     * `amount` and `status` are absent from $fillable by design — the model refuses
     * to let a client price a payment — so they are set with forceFill.
     */
    private function payment(Order $order): Payment
    {
        /*
         * Built unsaved then force-filled before the first save.
         *
         * `payment_reference` and `amount` are NOT NULL in the schema but absent from
         * $fillable by design — the model refuses to let a client reference or price a
         * payment. Calling create() first would fire an INSERT without them and trip
         * the NOT NULL constraint, so both have to be present before the row is
         * written, which is what PaymentService does too.
         */
        $payment = new Payment;

        $payment->forceFill([
            'uuid' => (string) Str::uuid(),
            'payment_reference' => 'PAY-'.strtoupper(Str::random(12)),
            'order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'business_id' => $this->business->id,
            'provider' => 'manual',
            'currency' => 'INR',
            'payment_method' => 'bank_transfer',
            'amount' => 25000,
            'status' => PaymentStatus::Paid,
        ])->save();

        return $payment;
    }

    /** Likewise no NegotiationFactory. `quote_id` is unique, so each needs its own quote. */
    private function negotiation(NegotiationParty $awaiting): Negotiation
    {
        $quote = $this->quote();

        return Negotiation::query()->create([
            'uuid' => (string) Str::uuid(),
            'quote_id' => $quote->id,
            'requirement_id' => $quote->requirement_id,
            'customer_id' => $this->customer->id,
            'business_id' => $this->business->id,
            'initiated_by' => NegotiationParty::Customer->value,
            'awaiting_party' => $awaiting->value,
            'round' => 1,
        ]);
    }

    /** @return list<string> The notification types this user has received. */
    private function typesFor(User $user): array
    {
        return $user->notifications()
            ->get()
            ->map(fn ($n) => $n->data['type'] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    // ------------------------------------------------------ the buyer's wait

    /**
     * The notification that justified building this layer.
     *
     * A buyer posts a requirement and then has no idea whether anything happened.
     * This is the moment they were waiting for.
     */
    public function test_a_buyer_is_told_when_a_quote_arrives(): void
    {
        event(new QuoteSent($this->quote()));

        $this->assertContains(NotificationType::QuoteReceived->value, $this->typesFor($this->customer));
    }

    /** The supplier pressed send. Telling them about it is how an inbox becomes noise. */
    public function test_the_supplier_is_not_told_about_their_own_sent_quote(): void
    {
        event(new QuoteSent($this->quote()));

        $this->assertSame([], $this->typesFor($this->owner));
    }

    public function test_the_notification_carries_a_deep_link_and_the_amount(): void
    {
        $quote = $this->quote();
        event(new QuoteSent($quote));

        $data = $this->customer->notifications()->first()->data;

        $this->assertSame('/dashboard/quotes/'.$quote->uuid, $data['url']);
        $this->assertSame('quote', $data['entity_type']);
        $this->assertSame($quote->uuid, $data['entity_id']);
        $this->assertArrayHasKey('amount', $data['meta']);
    }

    /**
     * Found in live testing: an unpriced quote produced "totalling ₹0.00".
     *
     * `total_amount` is a decimal cast, so an unpriced quote arrives as 0 rather
     * than null and slipped past a null-only guard. Stating a zero as a price reads
     * as an offer of free work, so the clause is omitted entirely instead.
     */
    public function test_an_unpriced_quote_does_not_claim_a_zero_total(): void
    {
        $quote = $this->quote();
        $quote->forceFill(['total_amount' => 0])->save();

        event(new QuoteSent($quote));

        $body = $this->customer->notifications()->first()->data['body'];

        $this->assertStringNotContainsString('0.00', $body);
        $this->assertStringNotContainsString('totalling', $body);
    }

    /**
     * INR uses Indian digit grouping, matching the frontend's
     * Intl.NumberFormat('en-IN'). A live check showed the notification saying
     * ₹125,000.00 where the quote page said ₹1,25,000.00 — the same amount written
     * two ways inside one product.
     */
    public function test_a_priced_quote_states_the_total_with_indian_grouping(): void
    {
        $quote = $this->quote();
        $quote->forceFill(['total_amount' => 125000, 'currency' => 'INR'])->save();

        event(new QuoteSent($quote));

        $body = $this->customer->notifications()->first()->data['body'];

        $this->assertStringContainsString('₹1,25,000.00', $body);
        $this->assertStringNotContainsString('125,000.00', $body);
    }

    public function test_crore_scale_amounts_group_correctly(): void
    {
        $quote = $this->quote();
        $quote->forceFill(['total_amount' => 12500000, 'currency' => 'INR'])->save();

        event(new QuoteSent($quote));

        $this->assertStringContainsString('₹1,25,00,000.00', $this->customer->notifications()->first()->data['body']);
    }

    /** Non-INR currencies keep Western grouping, which is correct for them. */
    public function test_non_inr_currencies_use_western_grouping(): void
    {
        $quote = $this->quote();
        $quote->forceFill(['total_amount' => 125000, 'currency' => 'USD'])->save();

        event(new QuoteSent($quote));

        $this->assertStringContainsString('$125,000.00', $this->customer->notifications()->first()->data['body']);
    }

    /** A long requirement title must not swamp a one-line body. */
    public function test_a_long_requirement_title_is_truncated_in_the_body(): void
    {
        $requirement = Requirement::factory()->create([
            'customer_id' => $this->customer->id,
            'title' => str_repeat('Extremely detailed requirement title ', 8),
        ]);

        event(new QuoteSent(Quote::factory()->create([
            'customer_id' => $this->customer->id,
            'business_id' => $this->business->id,
            'requirement_id' => $requirement->id,
        ])));

        $body = $this->customer->notifications()->first()->data['body'];

        $this->assertLessThan(200, mb_strlen($body));
        $this->assertStringContainsString('…', $body);
    }

    // ------------------------------------------------- direction of each event

    public function test_quote_viewed_and_accepted_notify_the_supplier_not_the_buyer(): void
    {
        $quote = $this->quote();

        event(new QuoteViewed($quote));
        event(new QuoteAccepted($quote));

        $ownerTypes = $this->typesFor($this->owner);
        $this->assertContains(NotificationType::QuoteViewed->value, $ownerTypes);
        $this->assertContains(NotificationType::QuoteAccepted->value, $ownerTypes);

        // The buyer did both of these things; neither is news to them.
        $this->assertSame([], $this->typesFor($this->customer));
    }

    public function test_an_accepted_enquiry_notifies_the_buyer(): void
    {
        $enquiry = Enquiry::factory()->accepted()->create([
            'customer_id' => $this->customer->id,
            'business_id' => $this->business->id,
            'requirement_id' => $this->requirement()->id,
        ]);

        event(new EnquiryAccepted($enquiry));

        $this->assertContains(NotificationType::EnquiryAccepted->value, $this->typesFor($this->customer));
        $this->assertSame([], $this->typesFor($this->owner));
    }

    public function test_a_new_order_notifies_the_supplier(): void
    {
        event(new OrderPlaced($this->order()));

        $this->assertContains(NotificationType::OrderPlaced->value, $this->typesFor($this->owner));
        $this->assertSame([], $this->typesFor($this->customer));
    }

    /** Fulfilment progress is the buyer's question; the supplier made the change. */
    public function test_an_order_status_change_notifies_the_buyer(): void
    {
        $order = $this->order();
        $order->forceFill(['status' => OrderStatus::Dispatched])->save();

        event(new OrderStatusChanged($order));

        $this->assertContains(NotificationType::OrderStatusChanged->value, $this->typesFor($this->customer));
        $this->assertSame([], $this->typesFor($this->owner));
    }

    public function test_order_completion_prompts_the_buyer_to_review(): void
    {
        event(new OrderCompleted($this->order()));

        $notification = $this->customer->notifications()->first();

        $this->assertSame(NotificationType::OrderCompleted->value, $notification->data['type']);
        $this->assertStringContainsString('review', strtolower($notification->data['title']));
    }

    public function test_a_successful_payment_notifies_the_receiving_supplier(): void
    {
        event(new PaymentSucceeded($this->payment($this->order())));

        $this->assertContains(NotificationType::PaymentSucceeded->value, $this->typesFor($this->owner));
    }

    public function test_a_failed_payment_notifies_the_paying_buyer(): void
    {
        event(new PaymentFailed($this->payment($this->order())));

        $this->assertContains(NotificationType::PaymentFailed->value, $this->typesFor($this->customer));
    }

    // ------------------------------------------------------------- both sides

    /** Nobody caused an expiry, and it is actionable for both parties. */
    public function test_expiry_notifies_both_parties(): void
    {
        event(new QuoteExpired($this->quote()));

        $this->assertContains(NotificationType::QuoteExpired->value, $this->typesFor($this->customer));
        $this->assertContains(NotificationType::QuoteExpired->value, $this->typesFor($this->owner));
    }

    /** Routed by the negotiation's own record of whose turn it is. */
    public function test_a_counter_offer_notifies_whichever_party_must_respond(): void
    {
        event(new NegotiationCountered($this->negotiation(NegotiationParty::Business)));

        $this->assertContains(NotificationType::NegotiationCountered->value, $this->typesFor($this->owner));
        $this->assertSame([], $this->typesFor($this->customer));
    }

    public function test_a_counter_offer_awaiting_the_customer_notifies_the_customer(): void
    {
        event(new NegotiationCountered($this->negotiation(NegotiationParty::Customer)));

        $this->assertContains(NotificationType::NegotiationCountered->value, $this->typesFor($this->customer));
        $this->assertSame([], $this->typesFor($this->owner));
    }

    // --------------------------------------------------------- failure posture

    /**
     * The most important resilience property.
     *
     * QuoteAccepted is what creates an order, and these events fire inside domain
     * transactions. If a notification failure could propagate, a broken inbox would
     * stop orders being created — so the failure has to be contained.
     *
     * Tested by removing the notifications table outright, which is a realistic
     * shape for the failure (a migration mid-deploy, a permissions problem) and
     * needs no mocking to provoke. An earlier version of this test tried to build a
     * business with no owner instead; that turned out to be impossible, because
     * `businesses.user_id` is NOT NULL — the schema guarantees every business has an
     * owner, so that branch was unreachable and proved nothing.
     */
    public function test_a_notification_failure_never_breaks_the_domain_event(): void
    {
        $quote = $this->quote();

        Schema::drop('notifications');

        // No exception may escape. If delivery were not contained, this would throw
        // and, in production, would take the surrounding domain transaction with it.
        event(new QuoteSent($quote));

        $this->assertTrue(true, 'The domain event completed despite notification storage being unavailable.');
    }

    /** The stored copy must not be rebuilt from live models on read. */
    public function test_the_payload_survives_deletion_of_the_underlying_record(): void
    {
        $quote = $this->quote();
        event(new QuoteSent($quote));

        $quote->delete();

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.attributes.notification_type', NotificationType::QuoteReceived->value)
            ->assertJsonPath('data.0.attributes.entity_type', 'quote');
    }

    // ------------------------------------------------------------------- API

    public function test_a_user_only_sees_their_own_notifications(): void
    {
        event(new QuoteSent($this->quote()));
        event(new OrderPlaced($this->order()));

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attributes.notification_type', NotificationType::QuoteReceived->value);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attributes.notification_type', NotificationType::OrderPlaced->value);
    }

    public function test_the_inbox_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();
    }

    public function test_the_unread_count_endpoint_returns_the_badge_number(): void
    {
        event(new QuoteSent($this->quote()));
        event(new QuoteSent($this->quote()));

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);
    }

    public function test_marking_one_read_reduces_the_unread_count(): void
    {
        event(new QuoteSent($this->quote()));
        $id = $this->customer->notifications()->first()->id;

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('data.attributes.is_read', true);

        $this->assertSame(0, $this->customer->unreadNotifications()->count());
    }

    /** A double-tap on a list item must not be an error. */
    public function test_marking_read_twice_is_idempotent(): void
    {
        event(new QuoteSent($this->quote()));
        $id = $this->customer->notifications()->first()->id;

        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/notifications/{$id}/read")->assertOk();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/notifications/{$id}/read")->assertOk();
    }

    public function test_mark_all_read_clears_the_badge(): void
    {
        event(new QuoteSent($this->quote()));
        event(new QuoteExpired($this->quote()));

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk();

        $this->assertSame(0, $this->customer->unreadNotifications()->count());
    }

    /** 404 rather than 403 — "forbidden" would confirm it exists for someone else. */
    public function test_one_user_cannot_mark_another_users_notification_read(): void
    {
        event(new OrderPlaced($this->order()));
        $ownersNotification = $this->owner->notifications()->first()->id;

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/notifications/{$ownersNotification}/read")
            ->assertNotFound();

        $this->assertSame(1, $this->owner->unreadNotifications()->count());
    }

    public function test_one_user_cannot_delete_another_users_notification(): void
    {
        event(new OrderPlaced($this->order()));
        $ownersNotification = $this->owner->notifications()->first()->id;

        $this->actingAs($this->customer, 'sanctum')
            ->deleteJson("/api/v1/notifications/{$ownersNotification}")
            ->assertNotFound();

        $this->assertSame(1, $this->owner->notifications()->count());
    }

    public function test_a_user_can_remove_their_own_notification(): void
    {
        event(new QuoteSent($this->quote()));
        $id = $this->customer->notifications()->first()->id;

        $this->actingAs($this->customer, 'sanctum')
            ->deleteJson("/api/v1/notifications/{$id}")
            ->assertOk();

        $this->assertSame(0, $this->customer->notifications()->count());
    }

    public function test_the_unread_filter_returns_only_unread(): void
    {
        event(new QuoteSent($this->quote()));
        event(new QuoteSent($this->quote()));

        $first = $this->customer->notifications()->first();
        $first->markAsRead();

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/notifications?filter[unread]=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /** Chronological, so the list does not reorder under the user on a poll. */
    public function test_notifications_are_returned_newest_first(): void
    {
        event(new QuoteSent($this->quote()));
        $this->travel(2)->minutes();
        event(new OrderCompleted($this->order()));

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.attributes.notification_type', NotificationType::OrderCompleted->value)
            ->assertJsonPath('data.1.attributes.notification_type', NotificationType::QuoteReceived->value);
    }

    // ------------------------------------------------------------- metadata

    /** Importance drives emphasis in the UI, so the mapping is worth pinning. */
    public function test_importance_and_icon_are_stored_with_the_notification(): void
    {
        event(new QuoteSent($this->quote()));

        $data = $this->customer->notifications()->first()->data;

        $this->assertSame('action', $data['importance']);
        $this->assertSame('receipt', $data['icon']);
    }

    public function test_every_notification_type_maps_to_an_importance_and_icon(): void
    {
        foreach (NotificationType::cases() as $type) {
            $this->assertContains($type->importance(), ['action', 'alert', 'info']);
            $this->assertNotSame('', $type->icon());
        }
    }
}
