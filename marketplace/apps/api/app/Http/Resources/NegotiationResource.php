<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A negotiation, shaped for whichever party is asking.
 *
 * The `can_*` flags encode the turn rules, so the UI can render exactly the
 * actions that are legal right now — including the asymmetry that only the
 * offering side may withdraw and only the receiving side may accept. The backend
 * re-derives all of it, so a stale or tampered client gains nothing.
 */
class NegotiationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $party = $viewer !== null ? $this->partyFor($viewer) : null;
        $isMyTurn = $party !== null && $this->awaits($party);
        $current = $this->currentRevision;
        $iMadeTheCurrentOffer = $party !== null && $current !== null && $current->authoredBy($party);

        return [
            'id' => $this->uuid,
            'type' => 'negotiation',
            'attributes' => [
                'status' => $this->status->value,
                'status_label' => $this->status->label(),
                'initiated_by' => $this->initiated_by->value,
                'awaiting_party' => $this->awaiting_party?->value,
                'round' => (int) $this->round,
                'is_active' => $this->isActive(),
                'is_expired' => $this->hasExpired(),
                'last_activity_at' => $this->last_activity_at?->toIso8601String(),
                'accepted_at' => $this->accepted_at?->toIso8601String(),
                'rejected_at' => $this->rejected_at?->toIso8601String(),
                'withdrawn_at' => $this->withdrawn_at?->toIso8601String(),
                'closed_at' => $this->closed_at?->toIso8601String(),
                'outcome_note' => $this->outcome_note,
                'created_at' => $this->created_at?->toIso8601String(),

                // Where the viewer stands.
                'viewer_party' => $party?->value,
                'is_my_turn' => $isMyTurn,
                // You may counter or decide only what the other side offered you.
                'can_counter' => $isMyTurn && ! $iMadeTheCurrentOffer && ! $this->hasExpired(),
                'can_accept' => $isMyTurn && ! $iMadeTheCurrentOffer && ! $this->hasExpired(),
                'can_reject' => $isMyTurn && ! $iMadeTheCurrentOffer,
                // ...and withdraw only what you offered.
                'can_withdraw' => $this->isActive() && $iMadeTheCurrentOffer,
                'can_message' => $party !== null && $this->isActive(),
            ],
            'relationships' => [
                'quote' => new QuoteResource($this->whenLoaded('quote')),
                'requirement' => new RequirementResource($this->whenLoaded('requirement')),
                'current_revision' => new QuoteRevisionResource($this->whenLoaded('currentRevision')),
                // The full immutable ledger, oldest first.
                'revisions' => QuoteRevisionResource::collection($this->whenLoaded('revisions')),
                'messages' => NegotiationMessageResource::collection($this->whenLoaded('messages')),
                // A business never learns who the customer is from here; the
                // customer sees the business as usual.
                'business' => new BusinessResource($this->whenLoaded('business')),
            ],
        ];
    }
}
