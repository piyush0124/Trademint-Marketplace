<?php

use App\Services\Payments\Gateways\HostedGateway;
use App\Services\Payments\Gateways\ManualGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | Payment providers (Phase 8)
    |--------------------------------------------------------------------------
    |
    | The marketplace is deliberately provider-agnostic. Nothing in the payment
    | domain names a provider: a gateway is resolved from this map by key, and
    | every gateway implements the same contract (App\Contracts\PaymentGateway).
    |
    | The shipped default is `manual`, which requires no paid third party and no
    | credentials: the customer pays out of band (bank transfer, UPI, cash) and
    | the receiving business or an admin confirms receipt server-side. That keeps
    | the whole order-to-payment flow usable today while leaving a real provider
    | a drop-in addition — add a class, add a key here, set two env vars.
    |
    */

    'default' => env('PAYMENTS_DEFAULT_PROVIDER', 'manual'),

    'providers' => [

        'manual' => [
            'driver' => ManualGateway::class,
            'label' => 'Bank transfer / offline payment',
            // Offline settlement is confirmed by a human, so there is no webhook
            // and no signature to verify.
            'supports_webhooks' => false,
            'instructions' => env('PAYMENTS_MANUAL_INSTRUCTIONS', 'Pay the business directly and they will confirm receipt.'),
        ],

        /*
         * A hosted-gateway shape, wired to a signature-verifying driver but with
         * no vendor SDK. Any real provider (Razorpay, Cashfree, Stripe, PayU)
         * slots in here: the webhook pipeline, idempotency, amount/currency/order
         * verification and refund records are all provider-independent already.
         */
        'hosted' => [
            'driver' => HostedGateway::class,
            'label' => env('PAYMENTS_HOSTED_LABEL', 'Online payment'),
            'supports_webhooks' => true,
            'key' => env('PAYMENTS_HOSTED_KEY'),
            // Never logged, never returned in an API response.
            'secret' => env('PAYMENTS_HOSTED_SECRET'),
            'webhook_secret' => env('PAYMENTS_HOSTED_WEBHOOK_SECRET'),
            // HMAC algorithm used to sign webhook payloads.
            'signature_algo' => env('PAYMENTS_HOSTED_SIGNATURE_ALGO', 'sha256'),
            'signature_header' => env('PAYMENTS_HOSTED_SIGNATURE_HEADER', 'X-Payment-Signature'),
        ],

    ],

    /*
    | How long an initiated payment may sit unconfirmed before it is treated as
    | abandoned. Nothing is deleted; the record is marked cancelled so the order
    | can be paid again.
    */
    'intent_ttl_minutes' => (int) env('PAYMENTS_INTENT_TTL_MINUTES', 60),

    /*
    | Webhook handling.
    */
    'webhooks' => [
        // Reject a webhook whose timestamp is older than this, to blunt replay
        // attacks. Null disables the check for providers that do not send one.
        'tolerance_seconds' => (int) env('PAYMENTS_WEBHOOK_TOLERANCE', 300),
        // Retain raw payloads for this many days for dispute investigation.
        'retention_days' => (int) env('PAYMENTS_WEBHOOK_RETENTION_DAYS', 90),
    ],

];
