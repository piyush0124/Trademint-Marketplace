<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A review.
 *
 * The reviewer is shown by first name only — enough to make the review feel real
 * without publishing a customer's full identity next to their purchase history.
 * The business's own view and an admin's view are the same; nobody gets the
 * customer's email or full record from here.
 */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isBusiness = $viewer !== null
            && $this->relationLoaded('business')
            && $this->business !== null
            && $viewer->id === $this->business->user_id;
        $isAuthor = $viewer !== null && $viewer->id === $this->customer_id;

        return [
            'id' => $this->uuid,
            'type' => 'review',
            'attributes' => [
                'rating' => (int) $this->rating,
                'comment' => $this->comment,
                'status' => $this->status->value,
                'status_label' => $this->status->label(),
                // First name only: identifiable enough to be credible, not enough
                // to expose the customer.
                'reviewer_name' => $this->reviewerName(),
                'business_response' => $this->business_response,
                'business_responded_at' => $this->business_responded_at?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),
                // Moderation detail is for staff and the affected parties only.
                'moderation_note' => $this->when($viewer?->isAdmin() ?? false, $this->moderation_note),
                'can_respond' => $isBusiness && ! $this->hasResponse(),
                'is_viewer_author' => $isAuthor,
            ],
            'relationships' => [
                'business' => new BusinessResource($this->whenLoaded('business')),
                // Which order earned the review, for the parties who can see it.
                'order' => $this->when($isBusiness || $isAuthor || ($viewer?->isAdmin() ?? false),
                    fn () => new OrderResource($this->whenLoaded('order'))),
            ],
        ];
    }

    private function reviewerName(): string
    {
        $this->loadMissing('customer');

        if ($this->customer === null) {
            return 'Customer';
        }

        $parts = preg_split('/\s+/', trim((string) $this->customer->name)) ?: [];

        return $parts[0] ?? 'Customer';
    }
}
