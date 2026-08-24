<?php

namespace App\Services\Payments;

/**
 * What a client needs to proceed with a payment, normalised across providers.
 *
 * Deliberately narrow: an optional provider reference, an optional redirect or
 * client token, and human instructions. Nothing secret ever travels in here — the
 * resource that serialises it is a public API response.
 */
final class GatewayIntent
{
    public function __construct(
        /** The provider's own id for this payment, when it issues one up front. */
        public readonly ?string $providerPaymentId = null,
        /** The provider's order/session id, when the model uses one. */
        public readonly ?string $providerOrderId = null,
        /** Where to send the customer, for redirect-based flows. */
        public readonly ?string $redirectUrl = null,
        /** Publishable key or client token for in-page checkout. Never a secret. */
        public readonly ?string $clientToken = null,
        /** What the customer should do next, in plain language. */
        public readonly ?string $instructions = null,
        /** Whether the provider will confirm asynchronously via webhook. */
        public readonly bool $awaitsWebhook = false,
        /** Non-sensitive extras worth storing on the payment record. */
        public readonly array $metadata = [],
    ) {}
}
