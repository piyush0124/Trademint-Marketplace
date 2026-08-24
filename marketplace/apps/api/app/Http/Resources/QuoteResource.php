<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A quotation.
 *
 * Money is returned as decimal strings straight from the database — never as
 * JSON numbers — so a consumer cannot lose precision by parsing them into
 * doubles, and the figures shown are provably the ones the backend calculated.
 *
 * `is_expired` is computed from expires_at rather than read off the status, so a
 * quote that lapsed a minute ago reports as expired even before anything has
 * relabelled it.
 */
class QuoteResource extends JsonResource
{
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
            'type' => 'quote',
            'attributes' => [
                'quote_number' => $this->quote_number,
                'title' => $this->title,
                'description' => $this->description,

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

                'payment_terms' => $this->payment_terms,
                'warranty_terms' => $this->warranty_terms,
                'cancellation_terms' => $this->cancellation_terms,
                'notes' => $this->notes,

                'status' => $this->status->value,
                'status_label' => $this->status->label(),
                'sent_at' => $this->sent_at?->toIso8601String(),
                'viewed_at' => $this->viewed_at?->toIso8601String(),
                'expires_at' => $this->expires_at?->toIso8601String(),
                'accepted_at' => $this->accepted_at?->toIso8601String(),
                'rejected_at' => $this->rejected_at?->toIso8601String(),
                'withdrawn_at' => $this->withdrawn_at?->toIso8601String(),
                'rejection_reason' => $this->rejection_reason,
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),

                'is_expired' => $this->hasExpired(),
                // Phase 6: how many offers this quote has been through, and the
                // negotiation to continue if one is open.
                'revision_count' => (int) $this->revision_count,
                'negotiation_id' => $this->whenLoaded('negotiation', fn () => $this->negotiation?->uuid),
                // Affordance hints only — every one is re-authorised server-side.
                'can_edit' => $isBusiness && $this->status->isEditable(),
                'can_send' => $isBusiness && $this->isDraft(),
                'can_withdraw' => $isBusiness && $this->isWithdrawable(),
                'can_decide' => $isCustomer && $this->isDecidableByCustomer(),
                'is_viewer_customer' => $isCustomer,
                'is_viewer_business' => $isBusiness,
            ],
            'relationships' => [
                'items' => QuoteItemResource::collection($this->whenLoaded('items')),
                'business' => new BusinessResource($this->whenLoaded('business')),
                'requirement' => new RequirementResource($this->whenLoaded('requirement')),
                'enquiry' => new EnquiryResource($this->whenLoaded('enquiry')),
                // The live offer, so a counter form can be seeded from the exact
                // terms currently on the table.
                'current_revision' => new QuoteRevisionResource($this->whenLoaded('currentRevision')),
            ],
        ];
    }
}
