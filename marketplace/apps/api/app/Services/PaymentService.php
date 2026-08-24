<?php

namespace App\Services;

use App\Enums\OrderPaymentStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Events\RefundRecorded;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Models\User;
use App\Services\Orders\ReferenceNumberGenerator;
use App\Services\Payments\GatewayNotification;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 8 — payment initiation, server-side settlement, webhooks and refunds.
 *
 * The rules this service exists to enforce, none of which a client can influence:
 *
 *  - A client never settles a payment. Only a signature-verified provider event or
 *    an authorised human (the receiving business, or an admin) can, and both go
 *    through settle() which re-verifies amount, currency and order.
 *  - The amount is taken from the order's outstanding balance, not the request.
 *  - One in-flight attempt per order, and an idempotency key makes a retried
 *    "pay now" return the same attempt instead of creating another.
 *  - Webhooks are idempotent at the database level: (provider, event_id) is unique,
 *    so a redelivery is recorded and ignored rather than settling twice.
 *  - The order's payment position is always *recomputed* from settled payments and
 *    refunds, never incremented — so no sequence of events can drift it.
 */
class PaymentService
{
    public const RELATIONS = ['order.items', 'refunds', 'business.location'];

    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly ReferenceNumberGenerator $numbers,
    ) {}

    /**
     * Start a payment for an order.
     *
     * @param  string|null  $idempotencyKey  client-supplied retry token
     */
    public function initiate(Order $order, User $actor, ?string $provider = null, ?string $idempotencyKey = null): Payment
    {
        $gateway = $this->gateways->requireDriver($provider);

        // Sweep any abandoned attempt first, so a stale intent cannot block a
        // customer from paying.
        $this->cancelLapsedAttempts($order);

        if (! $order->isPayable()) {
            throw ValidationException::withMessages([
                'order' => ['This order is not currently payable.'],
            ]);
        }

        $payment = DB::transaction(function () use ($order, $actor, $gateway, $idempotencyKey): Payment {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            // Idempotency: the same key always yields the same attempt.
            if ($idempotencyKey !== null) {
                $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            // Only one attempt may be in flight, otherwise two settlements could
            // race and overpay the order.
            $inFlight = Payment::query()->where('order_id', $locked->id)->inFlight()->first();

            if ($inFlight !== null) {
                return $inFlight;
            }

            // The amount is the order's outstanding balance — never a request field.
            $dueMinor = Money::toMinor($locked->amountDue());

            if ($dueMinor <= 0) {
                throw ValidationException::withMessages([
                    'order' => ['This order has nothing outstanding.'],
                ]);
            }

            return $this->numbers->withNumber('PAY', 'payments', 'payment_reference', function (string $reference) use ($locked, $actor, $gateway, $idempotencyKey, $dueMinor): Payment {
                $payment = new Payment([
                    'order_id' => $locked->id,
                    // Parties come from the order, never from input.
                    'customer_id' => $locked->customer_id,
                    'business_id' => $locked->business_id,
                    'provider' => $gateway->identifier(),
                    'currency' => $locked->currency,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $payment->payment_reference = $reference;
                $payment->forceFill([
                    'amount' => Money::fromMinor($dueMinor),
                    'status' => PaymentStatus::Pending,
                ])->save();

                $intent = $gateway->createIntent($payment);

                $payment->forceFill([
                    'status' => PaymentStatus::Initiated,
                    'initiated_at' => now(),
                    'provider_payment_id' => $intent->providerPaymentId,
                    'provider_order_id' => $intent->providerOrderId,
                    'expires_at' => now()->addMinutes((int) config('payments.intent_ttl_minutes', 60)),
                    'metadata' => array_merge($intent->metadata, [
                        'instructions' => $intent->instructions,
                        'redirect_url' => $intent->redirectUrl,
                        'client_token' => $intent->clientToken,
                        'awaits_webhook' => $intent->awaitsWebhook,
                        'initiated_by_user_id' => $actor->id,
                    ]),
                ])->save();

                $this->syncOrderPosition($locked);

                return $payment;
            });
        });

        return $payment->fresh(self::RELATIONS);
    }

    /**
     * Confirm an offline payment.
     *
     * The manual-settlement counterpart to a webhook: the *receiving business* (or
     * an admin) states that the money arrived. Authorisation is the controller's
     * job; this records who confirmed it and refuses to let a customer confirm
     * their own payment by checking the actor is not the payer.
     */
    public function confirmManually(Payment $payment, User $actor, ?string $reference = null): Payment
    {
        if ($payment->customer_id === $actor->id) {
            throw ValidationException::withMessages([
                'payment' => ['A payment must be confirmed by the receiving business, not the payer.'],
            ]);
        }

        if ($payment->status === PaymentStatus::Paid) {
            throw ValidationException::withMessages([
                'payment' => ['This payment has already been confirmed.'],
            ]);
        }

        if (! $payment->status->isInFlight()) {
            throw ValidationException::withMessages([
                'payment' => [sprintf('A %s payment cannot be confirmed.', strtolower($payment->status->label()))],
            ]);
        }

        return $this->settle(
            payment: $payment,
            status: PaymentStatus::Paid,
            amountMinor: $payment->amountMinor(),
            currency: $payment->currency,
            paymentMethod: $payment->payment_method ?? 'offline',
            failureReason: null,
            metadata: [
                'confirmed_by_user_id' => $actor->id,
                'confirmation_reference' => $reference,
            ],
        );
    }

    /**
     * Process a provider webhook.
     *
     * Every rejection path records the event and returns without touching money.
     * Returns the stored event so the controller can respond appropriately without
     * leaking detail to the caller.
     */
    public function handleWebhook(string $provider, string $rawPayload, array $headers): PaymentWebhookEvent
    {
        if (! $this->gateways->isConfigured($provider)) {
            // Unknown provider: nothing to verify against, so nothing is trusted.
            return PaymentWebhookEvent::create([
                'provider' => $provider,
                'event_id' => 'unknown-'.hash('sha256', $rawPayload.microtime()),
                'signature_valid' => false,
                'status' => PaymentWebhookEvent::STATUS_REJECTED,
                'error' => 'Unknown provider.',
            ]);
        }

        $gateway = $this->gateways->driver($provider);
        $signatureValid = $gateway->verifyWebhookSignature($rawPayload, $headers);
        $payload = json_decode($rawPayload, true);
        $payload = is_array($payload) ? $payload : [];

        // A bad signature is recorded and dropped. No state changes, and the
        // payload is kept so a genuine misconfiguration can be diagnosed.
        if (! $signatureValid) {
            return PaymentWebhookEvent::create([
                'provider' => $provider,
                'event_id' => (string) ($payload['event_id'] ?? $payload['id'] ?? 'unsigned-'.hash('sha256', $rawPayload)),
                'event_type' => $payload['event'] ?? $payload['type'] ?? null,
                'signature_valid' => false,
                'status' => PaymentWebhookEvent::STATUS_REJECTED,
                'error' => 'Signature verification failed.',
                'payload' => $payload,
            ]);
        }

        $notification = $gateway->parseWebhook($payload);

        if ($notification === null) {
            return PaymentWebhookEvent::create([
                'provider' => $provider,
                'event_id' => (string) ($payload['event_id'] ?? $payload['id'] ?? 'unparsed-'.hash('sha256', $rawPayload)),
                'event_type' => $payload['event'] ?? $payload['type'] ?? null,
                'signature_valid' => true,
                'status' => PaymentWebhookEvent::STATUS_IGNORED,
                'error' => 'Event is not actionable.',
                'payload' => $payload,
            ]);
        }

        return $this->processNotification($provider, $notification, $payload);
    }

    /**
     * Apply a verified notification exactly once.
     *
     * The insert of the webhook event is what claims the work: because
     * (provider, event_id) is unique, a redelivery fails that insert and is
     * recorded as a duplicate instead of settling the payment again.
     */
    private function processNotification(string $provider, GatewayNotification $notification, array $payload): PaymentWebhookEvent
    {
        $existing = PaymentWebhookEvent::query()
            ->where('provider', $provider)
            ->where('event_id', $notification->eventId)
            ->first();

        if ($existing !== null) {
            // Duplicate delivery. Nothing happens — this is the idempotency
            // guarantee, and it is asserted by test.
            return $existing;
        }

        $event = PaymentWebhookEvent::create([
            'provider' => $provider,
            'event_id' => $notification->eventId,
            'event_type' => $notification->eventType,
            'signature_valid' => true,
            'status' => PaymentWebhookEvent::STATUS_RECEIVED,
            'payload' => $payload,
        ]);

        $payment = Payment::query()
            ->where('payment_reference', $notification->paymentReference)
            ->where('provider', $provider)
            ->first();

        if ($payment === null) {
            $event->update([
                'status' => PaymentWebhookEvent::STATUS_REJECTED,
                'error' => 'No matching payment for that reference.',
                'processed_at' => now(),
            ]);

            return $event;
        }

        $event->payment_id = $payment->id;

        // Amount, currency and order are all re-verified against our own record.
        // A provider (or a forged-but-somehow-signed payload) reporting a
        // different amount is a mismatch to refuse, not a figure to accept.
        if ($notification->amountMinor !== $payment->amountMinor()) {
            $event->forceFill([
                'status' => PaymentWebhookEvent::STATUS_REJECTED,
                'error' => sprintf('Amount mismatch: expected %s, received %s.',
                    Money::fromMinor($payment->amountMinor()), Money::fromMinor($notification->amountMinor)),
                'processed_at' => now(),
            ])->save();

            return $event;
        }

        if (strtoupper($notification->currency) !== strtoupper($payment->currency)) {
            $event->forceFill([
                'status' => PaymentWebhookEvent::STATUS_REJECTED,
                'error' => sprintf('Currency mismatch: expected %s, received %s.', $payment->currency, $notification->currency),
                'processed_at' => now(),
            ])->save();

            return $event;
        }

        if ($payment->status === PaymentStatus::Paid && $notification->status === PaymentStatus::Paid) {
            $event->forceFill([
                'status' => PaymentWebhookEvent::STATUS_IGNORED,
                'error' => 'Payment already settled.',
                'processed_at' => now(),
            ])->save();

            return $event;
        }

        $this->settle(
            payment: $payment,
            status: $notification->status,
            amountMinor: $notification->amountMinor,
            currency: $notification->currency,
            paymentMethod: $notification->paymentMethod,
            failureReason: $notification->failureReason,
            metadata: array_merge($notification->metadata, ['provider_event_id' => $notification->eventId]),
            providerPaymentId: $notification->providerPaymentId,
        );

        $event->forceFill([
            'status' => PaymentWebhookEvent::STATUS_PROCESSED,
            'processed_at' => now(),
        ])->save();

        return $event;
    }

    /**
     * The single place a payment's outcome is written.
     *
     * Both settlement paths (webhook, manual confirmation) converge here, so the
     * order position update, the event dispatch and the audit metadata cannot be
     * skipped by adding a new caller.
     */
    private function settle(
        Payment $payment,
        PaymentStatus $status,
        int $amountMinor,
        string $currency,
        ?string $paymentMethod,
        ?string $failureReason,
        array $metadata = [],
        ?string $providerPaymentId = null,
    ): Payment {
        $settled = DB::transaction(function () use ($payment, $status, $amountMinor, $currency, $paymentMethod, $failureReason, $metadata, $providerPaymentId): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            // Re-checked under the lock: two concurrent settlements cannot both
            // mark the same attempt paid.
            if ($locked->status === PaymentStatus::Paid && $status === PaymentStatus::Paid) {
                return $locked;
            }

            if ($amountMinor !== $locked->amountMinor() || strtoupper($currency) !== strtoupper($locked->currency)) {
                throw ValidationException::withMessages([
                    'payment' => ['Payment amount or currency does not match the order.'],
                ]);
            }

            $locked->forceFill([
                'status' => $status,
                'payment_method' => $paymentMethod ?? $locked->payment_method,
                'provider_payment_id' => $providerPaymentId ?? $locked->provider_payment_id,
                'failure_reason' => $failureReason,
                'metadata' => array_merge((array) $locked->metadata, $metadata),
                'paid_at' => $status === PaymentStatus::Paid ? now() : $locked->paid_at,
                'failed_at' => $status === PaymentStatus::Failed ? now() : $locked->failed_at,
                'cancelled_at' => $status === PaymentStatus::Cancelled ? now() : $locked->cancelled_at,
            ])->save();

            $this->syncOrderPosition($locked->order);

            return $locked;
        });

        if ($settled->status === PaymentStatus::Paid) {
            PaymentSucceeded::dispatch($settled);
        }
        if ($settled->status === PaymentStatus::Failed) {
            PaymentFailed::dispatch($settled);
        }

        return $settled->fresh(self::RELATIONS);
    }

    /**
     * Record a refund against a settled payment.
     *
     * The amount is validated against what is actually refundable, so a request
     * cannot return more than was taken.
     */
    public function refund(Payment $payment, User $actor, string $amount, ?string $reason = null): Refund
    {
        if (! $payment->status->isRefundable()) {
            throw ValidationException::withMessages([
                'payment' => ['Only a settled payment can be refunded.'],
            ]);
        }

        $requestedMinor = Money::toMinor($amount);

        if ($requestedMinor <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['A refund must be greater than zero.'],
            ]);
        }

        if ($requestedMinor > $payment->refundableMinor()) {
            throw ValidationException::withMessages([
                'amount' => [sprintf('At most %s can be refunded on this payment.', Money::fromMinor($payment->refundableMinor()))],
            ]);
        }

        $refund = DB::transaction(function () use ($payment, $actor, $requestedMinor, $reason): Refund {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            // Re-checked under the lock so two partial refunds cannot together
            // exceed the payment.
            if ($requestedMinor > $locked->refundableMinor()) {
                throw ValidationException::withMessages([
                    'amount' => ['That amount is no longer available to refund.'],
                ]);
            }

            $refund = $this->numbers->withNumber('REF', 'refunds', 'refund_reference', function (string $reference) use ($locked, $actor, $requestedMinor, $reason): Refund {
                $refund = new Refund([
                    'payment_id' => $locked->id,
                    'order_id' => $locked->order_id,
                    'currency' => $locked->currency,
                    'reason' => $reason,
                    'requested_by_user_id' => $actor->id,
                ]);

                $refund->refund_reference = $reference;
                $refund->forceFill([
                    'amount' => Money::fromMinor($requestedMinor),
                    'status' => RefundStatus::Pending,
                ])->save();

                return $refund;
            });

            $result = $this->gateways->driver($locked->provider)->refund($refund, $requestedMinor);

            $refund->forceFill([
                'status' => $result->status,
                'provider_refund_id' => $result->providerRefundId,
                'failure_reason' => $result->failureReason,
                'metadata' => $result->metadata,
                'processed_at' => $result->status->isSettled() ? now() : null,
            ])->save();

            $this->recomputePaymentRefunds($locked);
            $this->syncOrderPosition($locked->order);

            return $refund;
        });

        RefundRecorded::dispatch($refund);

        return $refund->fresh(['payment', 'order']);
    }

    /**
     * Recompute a payment's refunded total from its settled refunds, and adjust its
     * status. Recomputed rather than incremented so it cannot drift.
     */
    private function recomputePaymentRefunds(Payment $payment): void
    {
        $refundedMinor = $payment->refunds()
            ->where('status', RefundStatus::Refunded->value)
            ->get()
            ->sum(fn (Refund $refund) => $refund->amountMinor());

        $status = match (true) {
            $refundedMinor <= 0 => $payment->status,
            $refundedMinor >= $payment->amountMinor() => PaymentStatus::Refunded,
            default => PaymentStatus::PartiallyRefunded,
        };

        $payment->forceFill([
            'amount_refunded' => Money::fromMinor($refundedMinor),
            'status' => $status,
            'refunded_at' => $refundedMinor > 0 ? now() : null,
        ])->save();
    }

    /**
     * Recompute the order's financial position from its payments and refunds.
     *
     * Always derived, never incremented: whatever sequence of webhooks, manual
     * confirmations and refunds occurred, the order reflects the sum of what
     * actually settled.
     */
    public function syncOrderPosition(Order $order): Order
    {
        $payments = $order->payments()->get();

        $paidMinor = $payments
            ->filter(fn (Payment $payment) => $payment->status->isSettled())
            ->sum(fn (Payment $payment) => $payment->amountMinor());

        $refundedMinor = $payments->sum(fn (Payment $payment) => Money::toMinor($payment->amount_refunded));

        $netMinor = max(0, $paidMinor - $refundedMinor);
        $totalMinor = Money::toMinor($order->total_amount);
        $hasInFlight = $payments->contains(fn (Payment $payment) => $payment->status->isInFlight());
        $hasFailure = $payments->contains(fn (Payment $payment) => $payment->status === PaymentStatus::Failed);

        $status = match (true) {
            $refundedMinor > 0 && $netMinor <= 0 => OrderPaymentStatus::Refunded,
            $refundedMinor > 0 => OrderPaymentStatus::PartiallyRefunded,
            $netMinor >= $totalMinor && $totalMinor > 0 => OrderPaymentStatus::Paid,
            $netMinor > 0 => OrderPaymentStatus::PartiallyPaid,
            $hasInFlight => OrderPaymentStatus::Pending,
            $hasFailure => OrderPaymentStatus::Failed,
            default => OrderPaymentStatus::Unpaid,
        };

        $order->forceFill([
            'amount_paid' => Money::fromMinor($netMinor),
            'amount_refunded' => Money::fromMinor($refundedMinor),
            'payment_status' => $status,
        ])->save();

        return $order;
    }

    /** Mark abandoned intents cancelled so the order can be paid again. */
    public function cancelLapsedAttempts(Order $order): int
    {
        $cancelled = 0;

        $order->payments()->inFlight()->get()->each(function (Payment $payment) use (&$cancelled): void {
            if (! $payment->hasLapsed()) {
                return;
            }

            $payment->forceFill([
                'status' => PaymentStatus::Cancelled,
                'cancelled_at' => now(),
                'failure_reason' => 'Payment was not completed in time.',
            ])->save();

            $cancelled++;
        });

        if ($cancelled > 0) {
            $this->syncOrderPosition($order->fresh());
        }

        return $cancelled;
    }
}
