<?php

namespace App\Services\Payments;

use App\Enums\RefundStatus;

/** Outcome of asking a provider to refund. */
final class GatewayRefundResult
{
    public function __construct(
        public readonly RefundStatus $status,
        public readonly ?string $providerRefundId = null,
        public readonly ?string $failureReason = null,
        public readonly array $metadata = [],
    ) {}
}
