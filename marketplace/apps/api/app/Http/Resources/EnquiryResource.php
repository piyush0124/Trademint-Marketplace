<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An enquiry, shaped for whichever party is asking.
 *
 * Follows the project's `{ id, type, attributes, relationships }` convention.
 * The customer's identity is only ever exposed to the customer themselves or an
 * admin — a business sees the requirement and the conversation, never who
 * raised it, consistent with how RequirementResource already behaves.
 *
 * `can_*` flags mirror EnquiryPolicy so the frontend can render the right
 * buttons; they are hints, and the backend re-checks every one of them.
 */
class EnquiryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isCustomer = $viewer !== null && $viewer->id === $this->customer_id;
        $isBusiness = $viewer !== null
            && $this->relationLoaded('business')
            && $this->business !== null
            && $viewer->id === $this->business->user_id;
        $isAdmin = $viewer !== null && $viewer->isAdmin();

        return [
            'id' => $this->uuid,
            'type' => 'enquiry',
            'attributes' => [
                'status' => $this->status->value,
                'status_label' => $this->status->label(),
                'initiated_by' => $this->initiated_by,
                'customer_message' => $this->customer_message,
                'business_message' => $this->business_message,
                'accepted_at' => $this->accepted_at?->toIso8601String(),
                'declined_at' => $this->declined_at?->toIso8601String(),
                'closed_at' => $this->closed_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
                'quotes_count' => $this->when(isset($this->quotes_count), fn () => (int) $this->quotes_count),
                // Business-side affordances.
                'can_respond' => $isBusiness && $this->awaitsBusinessResponse(),
                'can_quote' => $isBusiness && $this->allowsQuoting(),
                // Customer-side affordances.
                'can_cancel' => $isCustomer && ! $this->status->isFinal() && ! $this->isAccepted(),
                'is_viewer_customer' => $isCustomer,
                'is_viewer_business' => $isBusiness,
            ],
            'relationships' => [
                'requirement' => new RequirementResource($this->whenLoaded('requirement')),
                'business' => new BusinessResource($this->whenLoaded('business')),
                'match' => new RequirementMatchResource($this->whenLoaded('match')),
                // Only the customer themselves (or an admin) ever sees the
                // customer record on an enquiry.
                'customer' => $this->when(
                    ($isCustomer || $isAdmin) && $this->relationLoaded('customer'),
                    fn () => new UserResource($this->customer)
                ),
                'quotes' => QuoteResource::collection($this->whenLoaded('quotes')),
            ],
        ];
    }
}
