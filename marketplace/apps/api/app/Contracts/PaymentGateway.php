<?php

namespace App\Contracts;

use App\Models\Payment;
use App\Models\Refund;
use App\Services\Payments\GatewayIntent;
use App\Services\Payments\GatewayNotification;
use App\Services\Payments\GatewayRefundResult;

/**
 * The only thing the payment domain knows about a provider.
 *
 * Everything provider-specific lives behind these five methods: creating an
 * intent, verifying a webhook signature, translating a payload into a normalised
 * notification, and issuing a refund. PaymentService never branches on which
 * gateway it is talking to, which is what keeps Razorpay/Cashfree/Stripe/PayU a
 * configuration change rather than a rewrite.
 *
 * Implementations must never trust the caller: the *provider's* word (verified by
 * signature) or an authorised human's confirmation is the only thing that settles
 * a payment.
 */
interface PaymentGateway
{
    /** Key this gateway is registered under in config('payments.providers'). */
    public function identifier(): string;

    /** Human label for the payment UI. Safe to expose. */
    public function label(): string;

    /**
     * Start a payment. Returns whatever the client needs to proceed — a redirect
     * URL, a provider order id, or plain instructions for an offline transfer.
     * Must never include a secret.
     */
    public function createIntent(Payment $payment): GatewayIntent;

    /**
     * Verify that a webhook genuinely came from the provider.
     *
     * Returning false must cause the payload to be rejected without any state
     * change. Gateways without webhooks return false unconditionally.
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool;

    /**
     * Translate a verified provider payload into the normalised shape the
     * pipeline understands. Returning null means "not an event we act on".
     */
    public function parseWebhook(array $payload): ?GatewayNotification;

    /** Issue a refund with the provider. Amount is in minor units. */
    public function refund(Refund $refund, int $amountMinor): GatewayRefundResult;
}
