<?php

namespace Tests\Feature;

use App\Enums\EnquiryStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\RequirementStatus;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Category;
use App\Models\Enquiry;
use App\Models\Location;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Quote;
use App\Models\Requirement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 8 — payment initiation, server-side settlement, webhook verification and
 * idempotency, refunds, and the manipulation attempts that must fail.
 *
 * The webhook tests sign their payloads with the configured secret, which is what
 * makes them meaningful: an unsigned or wrongly-signed payload must change nothing.
 */
class PaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-webhook-secret';

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

        // Configure the generic hosted gateway with a known secret so signature
        // verification can be exercised for real.
        config()->set('payments.providers.hosted.webhook_secret', self::WEBHOOK_SECRET);
        config()->set('payments.providers.hosted.signature_algo', 'sha256');
        config()->set('payments.providers.hosted.signature_header', 'X-Payment-Signature');
    }

    private function makeBusiness(): Business
    {
        return Business::factory()->create([
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
        ]);
    }

    /** Drives the real pipeline: enquiry → quote → accept → order. */
    private function order(?Business $business = null, ?User $customer = null, int $unitPrice = 1000): Order
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
            'title' => 'Payable work',
            'validity_date' => now()->addDays(10)->toDateString(),
            'items' => [['description' => 'Item', 'item_type' => 'product', 'quantity' => 10, 'unit_price' => $unitPrice]],
        ])->assertCreated();

        $quote = Quote::where('uuid', $created->json('data.id'))->firstOrFail();
        $this->actingAs($business->user, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/send")->assertOk();
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/quotes/{$quote->uuid}/accept")->assertOk();

        return Order::where('quote_id', $quote->id)->firstOrFail();
    }

    private function sign(array $payload): array
    {
        $body = json_encode($payload);

        return [$body, hash_hmac('sha256', $body, self::WEBHOOK_SECRET)];
    }

    private function postWebhook(array $payload, ?string $signature = null, string $provider = 'hosted'): TestResponse
    {
        [$body, $validSignature] = $this->sign($payload);

        return $this->call(
            'POST',
            "/api/v1/webhooks/payments/$provider",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_PAYMENT_SIGNATURE' => $signature ?? $validSignature,
            ],
            $body
        );
    }

    /* ------------------------------------------------------------------ *
     | Methods + initiation
     * ------------------------------------------------------------------ */

    /** Provider list is public metadata only — never a secret. */
    public function test_payment_methods_are_listed_without_secrets(): void
    {
        $response = $this->getJson('/api/v1/payments/methods')->assertOk();

        $body = $response->json('data');
        $this->assertNotEmpty($body);
        $providers = array_column($body, 'provider');
        $this->assertContains('manual', $providers);

        // No secret material anywhere in the response.
        $raw = json_encode($body);
        $this->assertStringNotContainsString('secret', strtolower($raw));
        $this->assertStringNotContainsString(self::WEBHOOK_SECRET, $raw);
    }

    public function test_customer_initiates_a_payment_for_the_outstanding_amount(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/payments", ['provider' => 'manual'])
            ->assertCreated();

        // 10 x 1000 = 10000 outstanding.
        $response->assertJsonPath('data.attributes.amount', '10000.00');
        $response->assertJsonPath('data.attributes.status', PaymentStatus::Initiated->value);
        $response->assertJsonPath('data.attributes.provider', 'manual');
        $this->assertMatchesRegularExpression('/^PAY-\d{4}-\d{6}$/', $response->json('data.attributes.payment_reference'));

        $this->assertSame(OrderPaymentStatus::Pending, $order->fresh()->payment_status);
    }

    /** The amount is never taken from the request — this is the anti-manipulation test. */
    public function test_a_client_supplied_amount_is_ignored(): void
    {
        $order = $this->order();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/payments", [
                'provider' => 'manual',
                'amount' => 1,
                'status' => 'paid',
                'paid_at' => now()->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.attributes.amount', '10000.00')
            ->assertJsonPath('data.attributes.status', PaymentStatus::Initiated->value);

        $this->assertSame(OrderPaymentStatus::Pending, $order->fresh()->payment_status);
        $this->assertNull(Payment::firstOrFail()->paid_at);
    }

    public function test_repeating_an_idempotency_key_returns_the_same_payment(): void
    {
        $order = $this->order();

        $first = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/payments", ['provider' => 'manual', 'idempotency_key' => 'abc-123'])
            ->assertCreated();

        $second = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/payments", ['provider' => 'manual', 'idempotency_key' => 'abc-123'])
            ->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('payments', 1);
    }

    /** Only one attempt may be in flight, so an order cannot be double-paid. */
    public function test_a_second_attempt_returns_the_in_flight_one(): void
    {
        $order = $this->order();

        $first = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/payments", ['provider' => 'manual'])
            ->assertCreated();
        $second = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/payments", ['provider' => 'manual'])
            ->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_unknown_provider_is_refused(): void
    {
        $order = $this->order();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/payments", ['provider' => 'definitely-not-configured'])
            ->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
    }

    /* ------------------------------------------------------------------ *
     | Manual settlement
     * ------------------------------------------------------------------ */

    public function test_business_confirms_an_offline_payment_and_the_order_settles(): void
    {
        $order = $this->order();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/payments", ['provider' => 'manual'])
            ->assertCreated();

        $payment = Payment::firstOrFail();

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/payments/{$payment->uuid}/confirm", ['reference' => 'NEFT-99881'])
            ->assertOk()
            ->assertJsonPath('data.attributes.status', PaymentStatus::Paid->value);

        $fresh = $order->fresh();
        $this->assertSame(OrderPaymentStatus::Paid, $fresh->payment_status);
        $this->assertSame('10000.00', $fresh->amount_paid);
        $this->assertSame('0.00', $fresh->amountDue());
        $this->assertFalse($fresh->isPayable());
    }

    /** The payer must never be able to declare their own payment received. */
    public function test_customer_cannot_confirm_their_own_payment(): void
    {
        $order = $this->order();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/payments", ['provider' => 'manual'])->assertCreated();
        $payment = Payment::firstOrFail();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/payments/{$payment->uuid}/confirm")
            ->assertForbidden();

        $this->assertSame(PaymentStatus::Initiated, $payment->fresh()->status);
        $this->assertSame(OrderPaymentStatus::Pending, $order->fresh()->payment_status);
    }

    public function test_an_unrelated_business_cannot_confirm_a_payment(): void
    {
        $order = $this->order();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/payments", ['provider' => 'manual'])->assertCreated();
        $payment = Payment::firstOrFail();

        $outsider = $this->makeBusiness();
        $this->actingAs($outsider->user, 'sanctum')
            ->postJson("/api/v1/payments/{$payment->uuid}/confirm")
            ->assertForbidden();

        $this->assertSame(PaymentStatus::Initiated, $payment->fresh()->status);
    }

    public function test_confirming_twice_is_refused(): void
    {
        $order = $this->order();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/payments", ['provider' => 'manual'])->assertCreated();
        $payment = Payment::firstOrFail();

        $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/payments/{$payment->uuid}/confirm")->assertOk();
        $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/payments/{$payment->uuid}/confirm")->assertStatus(422);

        $this->assertSame('10000.00', $order->fresh()->amount_paid);
    }

    /* ------------------------------------------------------------------ *
     | Webhooks
     * ------------------------------------------------------------------ */

    private function hostedPayment(?Order $order = null): Payment
    {
        $order ??= $this->order();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/payments", ['provider' => 'hosted'])
            ->assertCreated();

        return Payment::firstOrFail();
    }

    public function test_a_signed_webhook_settles_the_payment(): void
    {
        $payment = $this->hostedPayment();

        $this->postWebhook([
            'event_id' => 'evt_1',
            'event' => 'payment.captured',
            'payment_reference' => $payment->payment_reference,
            'provider_payment_id' => 'pp_123',
            'status' => 'paid',
            'amount' => '10000.00',
            'currency' => $payment->currency,
            'method' => 'upi',
        ])->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame(PaymentStatus::Paid, $fresh->status);
        $this->assertSame('pp_123', $fresh->provider_payment_id);
        $this->assertSame('upi', $fresh->payment_method);
        $this->assertSame(OrderPaymentStatus::Paid, $fresh->order->payment_status);

        $event = PaymentWebhookEvent::firstOrFail();
        $this->assertTrue($event->signature_valid);
        $this->assertSame(PaymentWebhookEvent::STATUS_PROCESSED, $event->status);
    }

    /** An unsigned or wrongly-signed payload must change nothing. */
    public function test_an_invalid_signature_is_rejected(): void
    {
        $payment = $this->hostedPayment();

        $this->postWebhook([
            'event_id' => 'evt_bad',
            'payment_reference' => $payment->payment_reference,
            'status' => 'paid',
            'amount' => '10000.00',
            'currency' => $payment->currency,
        ], 'not-a-valid-signature')->assertStatus(400);

        $this->assertSame(PaymentStatus::Initiated, $payment->fresh()->status);
        $this->assertSame(OrderPaymentStatus::Pending, $payment->fresh()->order->payment_status);

        $event = PaymentWebhookEvent::firstOrFail();
        $this->assertFalse($event->signature_valid);
        $this->assertSame(PaymentWebhookEvent::STATUS_REJECTED, $event->status);
    }

    /** The core idempotency guarantee: a redelivered event settles nothing twice. */
    public function test_a_duplicate_webhook_is_ignored(): void
    {
        $payment = $this->hostedPayment();
        $payload = [
            'event_id' => 'evt_dup',
            'event' => 'payment.captured',
            'payment_reference' => $payment->payment_reference,
            'status' => 'paid',
            'amount' => '10000.00',
            'currency' => $payment->currency,
        ];

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        // One event row, one settlement, one paid amount.
        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertSame('10000.00', $payment->fresh()->order->amount_paid);
        $this->assertSame(1, Payment::where('status', PaymentStatus::Paid->value)->count());
    }

    public function test_an_amount_mismatch_is_rejected(): void
    {
        $payment = $this->hostedPayment();

        $this->postWebhook([
            'event_id' => 'evt_amount',
            'payment_reference' => $payment->payment_reference,
            'status' => 'paid',
            // The provider claims a fraction of what we asked for.
            'amount' => '1.00',
            'currency' => $payment->currency,
        ])->assertStatus(400);

        $this->assertSame(PaymentStatus::Initiated, $payment->fresh()->status);
        $this->assertSame('0.00', $payment->fresh()->order->amount_paid);
        $this->assertStringContainsString('Amount mismatch', PaymentWebhookEvent::firstOrFail()->error);
    }

    public function test_a_currency_mismatch_is_rejected(): void
    {
        $payment = $this->hostedPayment();

        $this->postWebhook([
            'event_id' => 'evt_currency',
            'payment_reference' => $payment->payment_reference,
            'status' => 'paid',
            'amount' => '10000.00',
            'currency' => 'USD',
        ])->assertStatus(400);

        $this->assertSame(PaymentStatus::Initiated, $payment->fresh()->status);
        $this->assertStringContainsString('Currency mismatch', PaymentWebhookEvent::firstOrFail()->error);
    }

    /** A reference that belongs to no payment must not settle anything. */
    public function test_an_unknown_payment_reference_is_rejected(): void
    {
        $this->hostedPayment();

        $this->postWebhook([
            'event_id' => 'evt_unknown',
            'payment_reference' => 'PAY-1999-000001',
            'status' => 'paid',
            'amount' => '10000.00',
            'currency' => 'INR',
        ])->assertStatus(400);

        $this->assertSame(0, Payment::where('status', PaymentStatus::Paid->value)->count());
    }

    /** A webhook for one order cannot settle another order's payment. */
    public function test_a_webhook_cannot_settle_a_different_order(): void
    {
        $firstPayment = $this->hostedPayment();

        $secondOrder = $this->order(unitPrice: 500);
        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$secondOrder->uuid}/payments", ['provider' => 'hosted'])
            ->assertCreated();

        // Amount of order two, reference of order one: the amount check catches it.
        $this->postWebhook([
            'event_id' => 'evt_cross',
            'payment_reference' => $firstPayment->payment_reference,
            'status' => 'paid',
            'amount' => '5000.00',
            'currency' => 'INR',
        ])->assertStatus(400);

        $this->assertSame('0.00', $firstPayment->fresh()->order->amount_paid);
        $this->assertSame('0.00', $secondOrder->fresh()->amount_paid);
    }

    public function test_a_failed_payment_leaves_the_order_payable(): void
    {
        $payment = $this->hostedPayment();

        $this->postWebhook([
            'event_id' => 'evt_fail',
            'payment_reference' => $payment->payment_reference,
            'status' => 'failed',
            'amount' => '10000.00',
            'currency' => $payment->currency,
            'failure_reason' => 'Card declined',
        ])->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame(PaymentStatus::Failed, $fresh->status);
        $this->assertSame('Card declined', $fresh->failure_reason);
        // The customer can try again.
        $this->assertTrue($fresh->order->isPayable());
        $this->assertSame(OrderPaymentStatus::Failed, $fresh->order->payment_status);
    }

    public function test_an_unknown_provider_webhook_is_rejected(): void
    {
        $this->postWebhook(['event_id' => 'evt_x', 'status' => 'paid'], null, 'nonexistent-provider')
            ->assertStatus(400);

        $this->assertSame(PaymentWebhookEvent::STATUS_REJECTED, PaymentWebhookEvent::firstOrFail()->status);
    }

    /** Webhooks must not leak whether a reference exists — the body stays neutral. */
    public function test_webhook_responses_do_not_leak_detail(): void
    {
        $payment = $this->hostedPayment();

        $rejected = $this->postWebhook([
            'event_id' => 'evt_leak',
            'payment_reference' => $payment->payment_reference,
            'status' => 'paid',
            'amount' => '3.00',
            'currency' => 'INR',
        ]);

        $rejected->assertStatus(400)->assertJsonPath('message', 'Webhook rejected.');
        $this->assertArrayNotHasKey('errors', $rejected->json());
    }

    /* ------------------------------------------------------------------ *
     | Refunds
     * ------------------------------------------------------------------ */

    private function paidPayment(): Payment
    {
        $order = $this->order();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/payments", ['provider' => 'manual'])->assertCreated();
        $payment = Payment::firstOrFail();
        $this->actingAs($this->business->user, 'sanctum')->postJson("/api/v1/payments/{$payment->uuid}/confirm")->assertOk();

        return $payment->fresh();
    }

    public function test_business_issues_a_full_refund(): void
    {
        $payment = $this->paidPayment();

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/payments/{$payment->uuid}/refund", ['amount' => '10000.00', 'reason' => 'Order cancelled.'])
            ->assertCreated()
            ->assertJsonPath('data.attributes.status', RefundStatus::Refunded->value)
            ->assertJsonPath('data.attributes.amount', '10000.00');

        $fresh = $payment->fresh();
        $this->assertSame(PaymentStatus::Refunded, $fresh->status);
        $this->assertSame(OrderPaymentStatus::Refunded, $fresh->order->payment_status);
        $this->assertSame('0.00', $fresh->order->amount_paid);
        $this->assertSame('10000.00', $fresh->order->amount_refunded);
    }

    public function test_business_issues_a_partial_refund(): void
    {
        $payment = $this->paidPayment();

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/payments/{$payment->uuid}/refund", ['amount' => '2500.00'])
            ->assertCreated();

        $fresh = $payment->fresh();
        $this->assertSame(PaymentStatus::PartiallyRefunded, $fresh->status);
        $this->assertSame('2500.00', $fresh->amount_refunded);
        $this->assertSame('7500.00', $fresh->order->amount_paid);
        $this->assertSame(OrderPaymentStatus::PartiallyRefunded, $fresh->order->payment_status);
    }

    /** Refunds cannot exceed what was taken, cumulatively. */
    public function test_refunds_cannot_exceed_the_payment(): void
    {
        $payment = $this->paidPayment();
        $owner = $this->business->user;

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/payments/{$payment->uuid}/refund", ['amount' => '6000.00'])->assertCreated();
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/payments/{$payment->uuid}/refund", ['amount' => '6000.00'])->assertStatus(422);

        $this->assertSame('6000.00', $payment->fresh()->amount_refunded);
    }

    public function test_an_unpaid_payment_cannot_be_refunded(): void
    {
        $order = $this->order();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->uuid}/payments", ['provider' => 'manual'])->assertCreated();
        $payment = Payment::firstOrFail();

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/payments/{$payment->uuid}/refund", ['amount' => '100.00'])
            ->assertStatus(422);

        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_customer_cannot_issue_a_refund(): void
    {
        $payment = $this->paidPayment();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/payments/{$payment->uuid}/refund", ['amount' => '100.00'])
            ->assertForbidden();

        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_negative_refund_is_rejected(): void
    {
        $payment = $this->paidPayment();

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/payments/{$payment->uuid}/refund", ['amount' => '-500.00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    /* ------------------------------------------------------------------ *
     | Access control
     * ------------------------------------------------------------------ */

    public function test_a_customer_cannot_pay_someone_elses_order(): void
    {
        $order = $this->order();
        $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);

        $this->actingAs($otherCustomer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/payments", ['provider' => 'manual'])
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_business_cannot_pay_an_order(): void
    {
        $order = $this->order();

        $this->actingAs($this->business->user, 'sanctum')
            ->postJson("/api/v1/orders/{$order->uuid}/payments", ['provider' => 'manual'])
            ->assertForbidden();
    }

    public function test_payments_are_scoped_to_the_caller(): void
    {
        $this->paidPayment();

        $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $otherBusiness = $this->makeBusiness();
        $otherOrder = $this->order($otherBusiness, $otherCustomer);
        $this->actingAs($otherCustomer, 'sanctum')->postJson("/api/v1/orders/{$otherOrder->uuid}/payments", ['provider' => 'manual'])->assertCreated();

        $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/payments')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($this->business->user, 'sanctum')->getJson('/api/v1/payments')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($otherBusiness->user, 'sanctum')->getJson('/api/v1/payments')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_a_stranger_cannot_read_a_payment(): void
    {
        $payment = $this->paidPayment();
        $outsider = $this->makeBusiness();

        $this->actingAs($outsider->user, 'sanctum')->getJson("/api/v1/payments/{$payment->uuid}")->assertForbidden();

        $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($otherCustomer, 'sanctum')->getJson("/api/v1/payments/{$payment->uuid}")->assertForbidden();
    }

    public function test_guest_cannot_access_payment_endpoints(): void
    {
        $payment = $this->paidPayment();
        $order = $payment->order;
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/payments')->assertUnauthorized();
        $this->getJson("/api/v1/payments/{$payment->uuid}")->assertUnauthorized();
        $this->postJson("/api/v1/orders/{$order->uuid}/payments")->assertUnauthorized();
        $this->postJson("/api/v1/payments/{$payment->uuid}/confirm")->assertUnauthorized();
        $this->postJson("/api/v1/payments/{$payment->uuid}/refund", ['amount' => '1.00'])->assertUnauthorized();
    }

    public function test_admin_can_read_and_refund_for_reconciliation(): void
    {
        $payment = $this->paidPayment();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/payments/{$payment->uuid}")->assertOk();
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/payments/{$payment->uuid}/refund", ['amount' => '1000.00', 'reason' => 'Goodwill.'])
            ->assertCreated();

        $this->assertSame('1000.00', $payment->fresh()->amount_refunded);
    }

    /** The order screen gets everything it needs to render a pay panel. */
    public function test_order_payments_endpoint_reports_the_balance_and_methods(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/orders/{$order->uuid}/payments")
            ->assertOk();

        $response->assertJsonPath('meta.amount_due', '10000.00');
        $response->assertJsonPath('meta.is_payable', true);
        $response->assertJsonPath('meta.currency', $order->currency);
        $this->assertNotEmpty($response->json('meta.methods'));
    }
}
