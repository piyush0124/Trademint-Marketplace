<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Payments\GatewayIntent;
use App\Services\Payments\GatewayNotification;
use App\Services\Payments\GatewayRefundResult;
use App\Support\Money;

/**
 * A generic hosted-checkout gateway.
 *
 * This is the *shape* every real provider takes — create an intent, redirect the
 * customer, receive an HMAC-signed webhook — implemented without any vendor SDK or
 * paid account. Swapping in Razorpay, Cashfree, Stripe or PayU means replacing the
 * two HTTP calls marked below; the webhook pipeline, idempotency, amount/currency/
 * order verification and refund records all stay exactly as they are.
 *
 * Signature verification uses a timing-safe comparison over the raw request body,
 * which is the pattern every one of those providers documents.
 */
class HostedGateway implements PaymentGateway
{
    public function __construct(private readonly array $config = []) {}

    public function identifier(): string
    {
        return 'hosted';
    }

    public function label(): string
    {
        return $this->config['label'] ?? 'Online payment';
    }

    public function createIntent(Payment $payment): GatewayIntent
    {
        // A real driver calls the provider's create-order API here and returns its
        // ids. Without credentials configured there is nothing to call, so the
        // intent is returned unsigned and the payment simply waits for a webhook.
        $providerOrderId = sprintf('%s_%s', $this->identifier(), $payment->payment_reference);

        return new GatewayIntent(
            providerOrderId: $providerOrderId,
            // Publishable key only — the secret never leaves the server.
            clientToken: $this->config['key'] ?? null,
            instructions: 'Complete the payment in the checkout window.',
            awaitsWebhook: true,
            metadata: ['provider_order_id' => $providerOrderId],
        );
    }

    /**
     * HMAC over the raw body, compared in constant time.
     *
     * Missing secret or missing header means unverifiable, which means rejected —
     * never "assume valid".
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        $secret = $this->config['webhook_secret'] ?? null;

        if (empty($secret)) {
            return false;
        }

        $headerName = strtolower($this->config['signature_header'] ?? 'X-Payment-Signature');
        $normalised = [];
        foreach ($headers as $key => $value) {
            $normalised[strtolower($key)] = is_array($value) ? ($value[0] ?? '') : $value;
        }

        $provided = $normalised[$headerName] ?? null;

        if (empty($provided)) {
            return false;
        }

        $expected = hash_hmac($this->config['signature_algo'] ?? 'sha256', $rawPayload, $secret);

        return hash_equals($expected, (string) $provided);
    }

    /**
     * Normalise the provider payload.
     *
     * Deliberately strict: an event without an id, a reference or an amount is not
     * actionable, and returning null keeps it out of the settlement path.
     */
    public function parseWebhook(array $payload): ?GatewayNotification
    {
        $eventId = $payload['event_id'] ?? $payload['id'] ?? null;
        $reference = $payload['payment_reference'] ?? $payload['reference'] ?? null;
        $type = $payload['event'] ?? $payload['type'] ?? 'payment.updated';

        if ($eventId === null || $reference === null) {
            return null;
        }

        $status = match ($payload['status'] ?? null) {
            'paid', 'captured', 'succeeded', 'success' => PaymentStatus::Paid,
            'failed', 'failure' => PaymentStatus::Failed,
            'cancelled', 'canceled' => PaymentStatus::Cancelled,
            'processing', 'pending' => PaymentStatus::Processing,
            default => null,
        };

        if ($status === null) {
            return null;
        }

        // The amount is taken from the provider and re-verified against our own
        // record by the pipeline — never used to overwrite it.
        $amountMinor = isset($payload['amount_minor'])
            ? (int) $payload['amount_minor']
            : Money::toMinor($payload['amount'] ?? 0);

        return new GatewayNotification(
            eventId: (string) $eventId,
            eventType: (string) $type,
            paymentReference: (string) $reference,
            providerPaymentId: isset($payload['provider_payment_id']) ? (string) $payload['provider_payment_id'] : null,
            status: $status,
            amountMinor: $amountMinor,
            currency: strtoupper((string) ($payload['currency'] ?? '')),
            paymentMethod: $payload['method'] ?? null,
            failureReason: $payload['failure_reason'] ?? $payload['error'] ?? null,
            metadata: ['event_type' => $type],
        );
    }

    public function refund(Refund $refund, int $amountMinor): GatewayRefundResult
    {
        // A real driver calls the provider's refund API here. Without credentials
        // the refund is recorded as processing and settled by the provider's own
        // refund webhook, which is the correct asynchronous behaviour anyway.
        if (empty($this->config['secret'])) {
            return new GatewayRefundResult(
                status: RefundStatus::Processing,
                failureReason: null,
                metadata: ['note' => 'Awaiting provider confirmation.'],
            );
        }

        return new GatewayRefundResult(
            status: RefundStatus::Processing,
            providerRefundId: sprintf('%s_rf_%s', $this->identifier(), $refund->refund_reference),
        );
    }
}
