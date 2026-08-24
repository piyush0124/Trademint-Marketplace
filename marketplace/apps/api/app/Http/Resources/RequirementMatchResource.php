<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One match, shaped for whoever is asking.
 *
 * The same record serves two very different readers. A customer wants to
 * know *which business* this is and why it was suggested; a matched business
 * wants to know *which requirement* it has been invited to. Both get the
 * score and the stored explanation, and neither gets the other side's
 * private detail: the customer's identity stays behind RequirementResource's
 * own gate, and a business is never told who else was matched.
 *
 * `score_breakdown` is included for transparency — a business can see which
 * dimension it lost points on, which is far more actionable than a bare
 * percentage.
 */
class RequirementMatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isMatchedBusiness = $viewer !== null
            && $this->relationLoaded('business')
            && $this->business !== null
            && $viewer->id === $this->business->user_id;

        return [
            'id' => $this->uuid,
            'type' => 'requirement_match',
            'attributes' => [
                'match_score' => (float) $this->match_score,
                'match_percentage' => $this->scorePercentage(),
                // "Why matched", most influential reason first.
                'reasons' => $this->reasons(),
                'score_breakdown' => $this->breakdown(),
                // Snapshot of the products/services that made this business
                // relevant, as at the time the match was scored.
                'capabilities' => $this->matchedCapabilities(),
                'invited_at' => $this->invited_at?->toIso8601String(),
                'viewed_at' => $this->viewed_at?->toIso8601String(),
                'response_status' => $this->response_status->value,
                'response_status_label' => $this->response_status->label(),
                'responded_at' => $this->responded_at?->toIso8601String(),
                'response_note' => $this->response_note,
                'question' => $this->question,
                'question_asked_at' => $this->question_asked_at?->toIso8601String(),
                // Only ever true for the business that was invited — the
                // customer never gets to accept or decline on its behalf.
                'can_respond' => $isMatchedBusiness && $this->isActionableByBusiness(),
            ],
            'relationships' => [
                'business' => new BusinessResource($this->whenLoaded('business')),
                'requirement' => new RequirementResource($this->whenLoaded('requirement')),
            ],
        ];
    }
}
