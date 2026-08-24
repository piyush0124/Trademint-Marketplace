<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Payments\GatewayIntent;
use App\Services\Payments\GatewayNotification;
use App\Services\Payments\GatewayRefundResult;

/**
 * Offline settlement: bank transfer, UPI, cheque, cash.
 *
 * The shipped default, because it needs no third-party account, no credentials and
 * no webhook endpoint — the marketplace is fully usable on day one. The customer
 * pays the business directly and the *business or an admin* confirms receipt
 * through an authenticated, authorised endpoint.
 *
 * That still satisfies the rule that a payment is only ever settled server-side by
 * a trusted actor: the customer cannot mark their own payment received, and the
 * confirmation is recorded against the confirming user.
 */
class ManualGateway implements PaymentGateway
{
    public function __construct(private readonly array $config = []) {}

    public function identifier(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return $this->config['label'] ?? 'Bank transfer / offline payment';
    }

    public function createIntent(Payment $payment): GatewayIntent
    {
        return new GatewayIntent(
            instructions: $this->config['instructions']
                ?? 'Pay the business directly and they will confirm receipt.',
            // Nothing to wait for from a provider — a human confirms.
            awaitsWebhook: false,
            metadata: ['settlement' => 'offline'],
        );
    }

    /** No webhooks exist for offline payments, so nothing can be verified. */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        return false;
    }

    public function parseWebhook(array $payload): ?GatewayNotification
    {
        return null;
    }

    /**
     * The money moved outside the platform, so the refund is likewise performed
     * offline. The record is created immediately as settled: it documents that the
     * business owes/returned the money, which is exactly what an offline refund is.
     */
    public function refund(Refund $refund, int $amountMinor): GatewayRefundResult
    {
        return new GatewayRefundResult(
            status: RefundStatus::Refunded,
            metadata: ['settlement' => 'offline'],
        );
    }
}
