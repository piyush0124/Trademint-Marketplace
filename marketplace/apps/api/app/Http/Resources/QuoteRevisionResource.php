<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One immutable offer from the negotiation history.
 *
 * Money is emitted as decimal strings exactly as stored, so a client can render
 * the historical figures without ever recomputing them — the point of keeping a
 * ledger in the first place.
 */
class QuoteRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => 'quote_revision',
            'attributes' => [
                'revision_number' => (int) $this->revision_number,
                'revision_type' => $this->revision_type,
                'authored_by' => $this->authored_by->value,
                'authored_by_label' => $this->authored_by->label(),
                'is_final_offer' => (bool) $this->is_final_offer,

                'subtotal' => $this->subtotal,
                'tax_amount' => $this->tax_amount,
                'delivery_fee' => $this->delivery_fee,
                'discount_amount' => $this->discount_amount,
                'other_charges' => $this->other_charges,
                'total_amount' => $this->total_amount,
                'currency' => $this->currency,

                'validity_date' => $this->validity_date?->toDateString(),
                'estimated_delivery_date' => $this->estimated_delivery_date?->toDateString(),
                'estimated_completion_date' => $this->estimated_completion_date?->toDateString(),
                'expires_at' => $this->expires_at?->toIso8601String(),
                'is_expired' => $this->hasExpired(),

                'payment_terms' => $this->payment_terms,
                'warranty_terms' => $this->warranty_terms,
                'cancellation_terms' => $this->cancellation_terms,
                'notes' => $this->notes,
                'message' => $this->message,

                'is_current' => (bool) $this->is_current,
                'superseded_at' => $this->superseded_at?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),
            ],
            'relationships' => [
                'items' => QuoteItemSnapshotResource::collection($this->whenLoaded('items')),
            ],
        ];
    }
}
