<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment attempt.
 *
 * Secrets never appear here. `metadata` is filtered to an allow-list rather than
 * dumped, because a provider may return more than a client should see — and a
 * webhook payload is stored on the event record, not exposed through this.
 */
class PaymentResource extends JsonResource
{
    /** The only metadata keys a client is ever shown. */
    private const PUBLIC_METADATA = ['instructions', 'redirect_url', 'client_token', 'awaits_webhook', 'settlement', 'provider_order_id'];

    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isCustomer = $viewer !== null && $viewer->id === $this->customer_id;
        $isBusiness = $viewer !== null
            && $this->relationLoaded('business')
            && $this->business !== null
            && $viewer->id === $this->business->user_id;

        return [
            'id' => $this->uuid,
            'type' => 'payment',
            'attributes' => [
                'payment_reference' => $this->payment_reference,
                'provider' => $this->provider,
                'provider_payment_id' => $this->when($isBusiness || $viewer?->isAdmin(), $this->provider_payment_id),
                'amount' => $this->amount,
                'amount_refunded' => $this->amount_refunded,
                'currency' => $this->currency,
                'status' => $this->status->value,
                'status_label' => $this->status->label(),
                'payment_method' => $this->payment_method,
                // A human-readable failure reason, never a stack trace or raw
                // provider error object.
                'failure_reason' => $this->failure_reason,
                'initiated_at' => $this->initiated_at?->toIso8601String(),
                'paid_at' => $this->paid_at?->toIso8601String(),
                'failed_at' => $this->failed_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
                'expires_at' => $this->expires_at?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),

                'checkout' => $this->publicMetadata(),
                'is_refundable' => $this->status->isRefundable(),
                'refundable_amount' => Money::fromMinor($this->refundableMinor()),
                // The business confirms offline receipt; the customer never can.
                'can_confirm' => $isBusiness && $this->status->isInFlight(),
                'can_refund' => $isBusiness && $this->status->isRefundable(),
                'is_viewer_customer' => $isCustomer,
                'is_viewer_business' => $isBusiness,
            ],
            'relationships' => [
                'order' => new OrderResource($this->whenLoaded('order')),
                'refunds' => RefundResource::collection($this->whenLoaded('refunds')),
                'business' => new BusinessResource($this->whenLoaded('business')),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function publicMetadata(): array
    {
        $metadata = (array) $this->metadata;

        return collect(self::PUBLIC_METADATA)
            ->filter(fn (string $key) => array_key_exists($key, $metadata) && $metadata[$key] !== null)
            ->mapWithKeys(fn (string $key) => [$key => $metadata[$key]])
            ->all();
    }
}
