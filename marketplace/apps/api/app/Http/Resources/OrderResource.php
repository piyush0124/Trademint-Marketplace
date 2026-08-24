<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\Dispute;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order, shaped for whichever party is asking.
 *
 * `available_transitions` is computed from the state machine and the viewer's
 * party, so the UI renders exactly the buttons the backend will accept — there is
 * no second copy of the transition rules in the frontend.
 *
 * `next_action` is the plain-language answer to "what should I do next", which is
 * what an order screen exists to tell you.
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isCustomer = $viewer !== null && $viewer->id === $this->customer_id;
        $isBusiness = $viewer !== null
            && $this->relationLoaded('business')
            && $this->business !== null
            && $viewer->id === $this->business->user_id;
        $party = $isCustomer ? 'customer' : ($isBusiness ? 'business' : ($viewer?->isAdmin() ? 'admin' : null));
        $reviewEligibility = $this->reviewEligibility($viewer, $isCustomer);

        return [
            'id' => $this->uuid,
            'type' => 'order',
            'attributes' => [
                'order_number' => $this->order_number,
                'status' => $this->status->value,
                'status_label' => $this->status->label(),
                'payment_status' => $this->payment_status->value,
                'payment_status_label' => $this->payment_status->label(),
                'fulfilment_status' => $this->fulfilment_status->value,
                'fulfilment_status_label' => $this->fulfilment_status->label(),

                'subtotal' => $this->subtotal,
                'tax_amount' => $this->tax_amount,
                'delivery_fee' => $this->delivery_fee,
                'discount_amount' => $this->discount_amount,
                'other_charges' => $this->other_charges,
                'total_amount' => $this->total_amount,
                'amount_paid' => $this->amount_paid,
                'amount_refunded' => $this->amount_refunded,
                'amount_due' => $this->amountDue(),
                'currency' => $this->currency,

                'expected_delivery_date' => $this->expected_delivery_date?->toDateString(),
                'expected_completion_date' => $this->expected_completion_date?->toDateString(),
                'customer_notes' => $this->customer_notes,
                'business_notes' => $this->business_notes,
                'cancellation_reason' => $this->cancellation_reason,

                'placed_at' => $this->placed_at?->toIso8601String(),
                'confirmed_at' => $this->confirmed_at?->toIso8601String(),
                'completed_at' => $this->completed_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),

                'viewer_party' => $party,
                'is_payable' => $this->isPayable(),
                // Statuses this viewer may actually set, right now.
                'available_transitions' => $this->transitionsFor($party),
                'next_action' => $this->nextAction($party),

                // Phase 9/10 affordances. Both are computed from the same services
                // that enforce them, so the UI cannot offer an action the API would
                // refuse, and the reason a button is missing is explainable.
                'can_review' => $reviewEligibility['eligible'],
                'review_blocked_reason' => $isCustomer ? $reviewEligibility['reason'] : null,
                'can_dispute' => $this->canDispute($party),
                'dispute_reasons' => Dispute::REASONS,
            ],
            'relationships' => [
                'items' => OrderItemResource::collection($this->whenLoaded('items')),
                'business' => new BusinessResource($this->whenLoaded('business')),
                'requirement' => new RequirementResource($this->whenLoaded('requirement')),
                'quote' => new QuoteResource($this->whenLoaded('quote')),
                'status_history' => OrderStatusHistoryResource::collection($this->whenLoaded('statusHistory')),
                'review' => new ReviewResource($this->whenLoaded('review')),
                'active_dispute' => $this->activeDisputeSummary($party),
            ],
        ];
    }

    /**
     * Whether the viewer may review, delegated to ReviewService so the rule lives
     * in exactly one place. Only evaluated for the customer on a completed order —
     * every other case is a guaranteed no, and skipping it keeps order lists from
     * issuing a query per row.
     *
     * @return array{eligible: bool, reason: string|null}
     */
    private function reviewEligibility(?User $viewer, bool $isCustomer): array
    {
        if (! $isCustomer || $viewer === null) {
            return ['eligible' => false, 'reason' => null];
        }

        // Already-reviewed is answerable from the eager-loaded relation, which is
        // the common case on a list of completed orders. ReviewService::eligibility
        // would reach the same conclusion, but with a query per row.
        if ($this->relationLoaded('review') && $this->review !== null) {
            return ['eligible' => false, 'reason' => ReviewService::ALREADY_REVIEWED];
        }

        // Otherwise ask the service. It answers status and ownership questions
        // before touching the database, so an in-progress order costs nothing.
        return app(ReviewService::class)->eligibility($this->resource, $viewer);
    }

    /**
     * Either party may dispute a live order, once at a time. Mirrors
     * DisputeService::raise rather than restating it loosely.
     */
    private function canDispute(?string $party): bool
    {
        if (! in_array($party, ['customer', 'business'], true)) {
            return false;
        }

        if ($this->status === OrderStatus::Cancelled) {
            return false;
        }

        return $this->activeDispute() === null;
    }

    /** Enough of the open dispute to link to it; details live on the dispute API. */
    private function activeDisputeSummary(?string $party): ?array
    {
        if ($party === null) {
            return null;
        }

        $dispute = $this->activeDispute();

        if ($dispute === null) {
            return null;
        }

        return [
            'id' => $dispute->uuid,
            'reference' => $dispute->reference,
            'status' => $dispute->status->value,
            'status_label' => $dispute->status->label(),
            'raised_by_party' => $dispute->raised_by_party,
        ];
    }

    /**
     * Intersect "legal next states" with "states this party may set", mirroring
     * OrderService::assertPartyMaySet.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function transitionsFor(?string $party): array
    {
        if ($party === null) {
            return [];
        }

        $allowed = collect($this->status->allowedTransitions());

        if ($party !== 'admin') {
            $allowed = $allowed->filter(function (OrderStatus $target) use ($party): bool {
                if ($target === OrderStatus::Cancelled) {
                    return $party === 'customer' ? $this->isCancellableByCustomer() : $this->isCancellableByBusiness();
                }

                if ($target === OrderStatus::Completed) {
                    return $party === 'customer';
                }

                return $party === 'business'
                    ? in_array($target, OrderStatus::businessControlled(), true)
                    : in_array($target, OrderStatus::customerControlled(), true);
            });
        }

        return $allowed
            ->map(fn (OrderStatus $target) => ['value' => $target->value, 'label' => $target->label()])
            ->values()
            ->all();
    }

    /** One sentence telling this party what is expected of them next. */
    private function nextAction(?string $party): ?string
    {
        if ($party === null || $this->status->isFinal()) {
            return null;
        }

        return match (true) {
            $this->status === OrderStatus::Pending && $party === 'business' => 'Confirm this order to begin work.',
            $this->status === OrderStatus::Pending && $party === 'customer' => 'Waiting for the business to confirm.',
            $party === 'customer' && $this->isPayable() => 'Payment is outstanding on this order.',
            $party === 'customer' && $this->isCompletableByCustomer() => 'Confirm completion once you are satisfied.',
            $party === 'business' && $this->status->isUnderway() => 'Keep the fulfilment status up to date.',
            $party === 'business' => 'Progress the order as work advances.',
            default => 'Waiting on the other party.',
        };
    }
}
