<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;

/**
 * A verified provider event, normalised.
 *
 * `amountMinor` and `currency` are carried explicitly because the pipeline
 * re-verifies them against the payment before settling anything — a provider
 * reporting a different amount than we asked for is a mismatch to reject, not a
 * figure to accept.
 */
final class GatewayNotification
{
    public function __construct(
        /** Provider's unique id for this *event*, used for webhook idempotency. */
        public readonly string $eventId,
        public readonly string $eventType,
        /** Our payment reference, echoed back by the provider. */
        public readonly ?string $paymentReference,
        public readonly ?string $providerPaymentId,
        public readonly PaymentStatus $status,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $failureReason = null,
        public readonly array $metadata = [],
    ) {}
}
