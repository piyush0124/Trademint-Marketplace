<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\ReviewStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 9 — reviews.
 *
 * Eligibility is the whole security model here, and it is deliberately narrow: only
 * the customer on a *completed* order may review it, exactly once. That kills
 * review-stuffing, self-reviewing and reviewing a business you never traded with,
 * because there is no route to a review that does not pass through a completed
 * order.
 *
 * The business rating is always *recomputed* from published reviews rather than
 * incremented, so it cannot drift and moderation is immediately reflected.
 */
class ReviewService
{
    public const RELATIONS = ['business.location', 'order'];

    /**
     * The ineligibility messages, named so the order API can reuse the exact text
     * instead of keeping a second copy that drifts.
     */
    public const NOT_THE_CUSTOMER = 'Only the customer on this order can review it.';

    public const NOT_COMPLETED = 'You can review a business once the order is completed.';

    public const OWN_BUSINESS = 'You cannot review your own business.';

    public const ALREADY_REVIEWED = 'You have already reviewed this order.';

    /** Customer reviews a completed order. */
    public function create(Order $order, User $customer, int $rating, ?string $comment = null): Review
    {
        $this->assertEligible($order, $customer);

        $review = DB::transaction(function () use ($order, $rating, $comment): Review {
            // The unique index on order_id is the real guarantee; the lock makes a
            // double submission return a clean 422 rather than a database error.
            $existing = Review::query()->where('order_id', $order->id)->lockForUpdate()->first();

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'order' => [self::ALREADY_REVIEWED],
                ]);
            }

            $review = Review::create([
                'order_id' => $order->id,
                // Derived from the order, never from input.
                'business_id' => $order->business_id,
                'customer_id' => $order->customer_id,
                'rating' => $rating,
                'comment' => $comment,
            ]);

            $review->forceFill(['status' => ReviewStatus::Published])->save();

            return $review;
        });

        $this->recalculateRating($order->business);

        return $review->load(self::RELATIONS);
    }

    /**
     * Business replies. Once only, and it cannot touch the review itself — the
     * model enforces that independently.
     */
    public function respond(Review $review, string $response): Review
    {
        if ($review->hasResponse()) {
            throw ValidationException::withMessages([
                'response' => ['You have already responded to this review.'],
            ]);
        }

        $review->forceFill([
            'business_response' => $response,
            'business_responded_at' => now(),
        ])->save();

        return $review->fresh(self::RELATIONS);
    }

    /**
     * Admin moderation. Hiding removes a review from the public rating but never
     * deletes it, and the reason is recorded against the moderator.
     */
    public function moderate(Review $review, User $admin, ReviewStatus $status, ?string $note = null): Review
    {
        $review->forceFill([
            'status' => $status,
            'moderated_by_user_id' => $admin->id,
            'moderation_note' => $note,
            'moderated_at' => now(),
        ])->save();

        $this->recalculateRating($review->business);

        return $review->fresh(self::RELATIONS);
    }

    /**
     * Recompute a business's public rating from its visible reviews.
     *
     * Derived, never accumulated: hiding a review immediately corrects the average,
     * and no sequence of events can leave a stale figure behind.
     */
    public function recalculateRating(Business $business): Business
    {
        $reviews = $business->reviews()->publicallyVisible()->get();

        $business->forceFill([
            'rating' => $reviews->isEmpty() ? null : round($reviews->avg('rating'), 2),
            'review_count' => $reviews->count(),
        ])->save();

        return $business;
    }

    /**
     * Whether this customer may review this order, and why not if they may not.
     *
     * Kept public so the order API can advertise `can_review` without duplicating
     * the rules.
     */
    public function eligibility(Order $order, User $customer): array
    {
        if ($order->customer_id !== $customer->id) {
            return ['eligible' => false, 'reason' => self::NOT_THE_CUSTOMER];
        }

        if ($order->status !== OrderStatus::Completed) {
            return ['eligible' => false, 'reason' => self::NOT_COMPLETED];
        }

        $order->loadMissing('business');

        // A user who somehow owns both sides cannot review themselves.
        if ($order->business !== null && $order->business->user_id === $customer->id) {
            return ['eligible' => false, 'reason' => self::OWN_BUSINESS];
        }

        if (Review::query()->where('order_id', $order->id)->exists()) {
            return ['eligible' => false, 'reason' => self::ALREADY_REVIEWED];
        }

        return ['eligible' => true, 'reason' => null];
    }

    private function assertEligible(Order $order, User $customer): void
    {
        $eligibility = $this->eligibility($order, $customer);

        if (! $eligibility['eligible']) {
            throw ValidationException::withMessages([
                'order' => [$eligibility['reason']],
            ]);
        }
    }
}
