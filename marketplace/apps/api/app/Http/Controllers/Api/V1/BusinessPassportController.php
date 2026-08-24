<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MatchResponseStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Http\Resources\ReviewResource;
use App\Models\Business;
use App\Services\Trust\TrustScoreCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 9 — the Business Passport: a structured, public, evidence-based profile.
 *
 * Two principles govern what appears here:
 *
 *  1. **No invented metrics.** Every number is counted from real records. Where
 *     there is no evidence the field is null and the frontend says "not enough data
 *     yet" — it never shows a zero dressed up as a score.
 *  2. **No private data.** Document *statuses* are published, never the documents.
 *     GST numbers, emails and customer identities are not exposed. What a guest sees
 *     is what any customer sees.
 */
class BusinessPassportController extends Controller
{
    public function __construct(private readonly TrustScoreCalculator $trust) {}

    public function show(Request $request, string $slug): JsonResponse
    {
        $business = Business::query()
            ->where('slug', $slug)
            ->with(['location', 'category', 'industries', 'serviceAreas'])
            ->firstOrFail();

        $trust = $this->trust->calculate($business);

        return response()->json([
            'success' => true,
            'message' => 'Business passport retrieved successfully.',
            'data' => [
                'id' => $business->slug,
                'type' => 'business_passport',
                'attributes' => [
                    'verification_status' => $business->verificationStatus()->value,
                    'verification_status_label' => $business->verificationStatus()->label(),
                    'verified_at' => $business->verified_at?->toDateString(),
                    'is_fully_verified' => $business->isFullyVerified(),
                    'is_suspended' => $business->isSuspended(),

                    // Trust score plus the full factor breakdown — transparent by
                    // design, so a business can see what to improve.
                    'trust_score' => $trust['score'],
                    'trust_factors' => $trust['factors'],

                    'rating' => $business->rating,
                    'review_count' => (int) $business->review_count,
                    'completed_orders' => $this->completedOrders($business),
                    'response_rate' => $this->responseRate($business),
                    'response_time_hours' => $this->responseTimeHours($business),
                    'years_in_business' => $business->years_in_business,
                    'established_on' => $business->established_on?->toDateString(),
                    'member_since' => $business->created_at?->toDateString(),
                    'active_listings' => $business->listings()->published()->count(),
                    'products_count' => $business->products()->where('is_active', true)->count(),
                    'services_count' => $business->services()->where('is_active', true)->count(),
                    // Statuses only. The documents themselves are private.
                    'document_status' => $this->documentStatus($business),
                ],
                'relationships' => [
                    'business' => new BusinessResource($business),
                    'recent_reviews' => ReviewResource::collection(
                        $business->reviews()->publicallyVisible()->with('business')->latest()->limit(5)->get()
                    ),
                ],
            ],
        ]);
    }

    /** Public review list for a business. */
    public function reviews(Request $request, string $slug): JsonResponse
    {
        $business = Business::query()->where('slug', $slug)->firstOrFail();

        $perPage = min((int) $request->input('per_page', 20), 100);

        $reviews = $business->reviews()
            ->publicallyVisible()
            ->with(['business', 'customer'])
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Reviews retrieved successfully.',
            'data' => ReviewResource::collection($reviews->items()),
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
                'last_page' => $reviews->lastPage(),
                'rating' => $business->rating,
                'review_count' => (int) $business->review_count,
                'rating_breakdown' => $this->ratingBreakdown($business),
            ],
        ]);
    }

    private function completedOrders(Business $business): int
    {
        return $business->orders()->where('status', OrderStatus::Completed->value)->count();
    }

    /**
     * Share of match invitations this business actually answered. Null until there
     * is enough history to be meaningful — never a flattering default.
     */
    private function responseRate(Business $business): ?int
    {
        $invited = $business->requirementMatches()->whereNotNull('invited_at')->count();

        if ($invited < 3) {
            return null;
        }

        $responded = $business->requirementMatches()
            ->where('response_status', '!=', MatchResponseStatus::Pending->value)
            ->count();

        return (int) round(($responded / $invited) * 100);
    }

    /**
     * Median hours from invitation to response, from real timestamps. Null when
     * there is nothing to measure.
     */
    private function responseTimeHours(Business $business): ?float
    {
        $durations = $business->requirementMatches()
            ->whereNotNull('invited_at')
            ->whereNotNull('responded_at')
            ->get()
            ->map(fn ($match) => $match->invited_at->diffInMinutes($match->responded_at) / 60)
            ->filter(fn ($hours) => $hours >= 0)
            ->sort()
            ->values();

        if ($durations->isEmpty()) {
            return null;
        }

        // Median rather than mean: one holiday weekend should not define a
        // business's reputation.
        $middle = (int) floor($durations->count() / 2);

        $median = $durations->count() % 2 === 0
            ? (($durations[$middle - 1] + $durations[$middle]) / 2)
            : $durations[$middle];

        return round((float) $median, 1);
    }

    /** @return array<string, int> */
    private function documentStatus(Business $business): array
    {
        $documents = $business->documents()->get();

        return [
            'total' => $documents->count(),
            'approved' => $documents->where('status', 'approved')->count(),
            'pending' => $documents->where('status', 'pending')->count(),
            'rejected' => $documents->where('status', 'rejected')->count(),
        ];
    }

    /** @return array<int, int> */
    private function ratingBreakdown(Business $business): array
    {
        $counts = $business->reviews()
            ->publicallyVisible()
            ->selectRaw('rating, count(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        return collect(range(5, 1))
            ->mapWithKeys(fn (int $star) => [$star => (int) ($counts[$star] ?? 0)])
            ->all();
    }
}
