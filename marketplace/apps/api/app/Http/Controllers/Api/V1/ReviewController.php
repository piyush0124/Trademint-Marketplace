<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Order;
use App\Models\Review;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 9 — reviews.
 *
 * A review is always created against an *order*, never against a business
 * directly: that is what makes "you must have traded with them" structural rather
 * than a rule someone could forget to check.
 */
class ReviewController extends Controller
{
    private const RELATIONS = ['business.location', 'order', 'customer'];

    public function __construct(private readonly ReviewService $reviews) {}

    /** The caller's reviews: written (customer) or received (business). */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Review::class);

        $user = $request->user();
        $query = Review::query()->with(self::RELATIONS);

        if ($user->isCustomer()) {
            $query->where('customer_id', $user->id);
        } elseif ($user->isOwner()) {
            $business = $user->business;

            if ($business === null) {
                return response()->json([
                    'success' => true,
                    'message' => 'Create your business profile to start receiving reviews.',
                    'data' => [],
                    'meta' => ['current_page' => 1, 'per_page' => 0, 'total' => 0, 'last_page' => 1],
                ]);
            }

            $query->forBusiness($business->id);
        }
        // Admin: unscoped, for moderation.

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $reviews = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Reviews retrieved successfully.',
            'data' => ReviewResource::collection($reviews->items()),
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
                'last_page' => $reviews->lastPage(),
            ],
        ]);
    }

    /**
     * Customer reviews a completed order.
     *
     * Authorised as an ability on the order, because eligibility is a property of
     * the order (completed, yours, not already reviewed).
     */
    public function store(Request $request, Order $order): JsonResponse
    {
        $this->authorize('review', $order);

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $review = $this->reviews->create(
            $order,
            $request->user(),
            (int) $validated['rating'],
            $validated['comment'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Thank you — your review has been published.',
            'data' => new ReviewResource($review->load(self::RELATIONS)),
        ], 201);
    }

    public function show(Review $review): JsonResponse
    {
        $this->authorize('view', $review);

        return response()->json([
            'success' => true,
            'message' => 'Review retrieved successfully.',
            'data' => new ReviewResource($review->load(self::RELATIONS)),
        ]);
    }

    /** The reviewed business replies, once. It can never edit the review itself. */
    public function respond(Request $request, Review $review): JsonResponse
    {
        $this->authorize('respond', $review);

        $validated = $request->validate([
            'response' => ['required', 'string', 'min:2', 'max:2000'],
        ]);

        $review = $this->reviews->respond($review, $validated['response']);

        return response()->json([
            'success' => true,
            'message' => 'Your response has been published.',
            'data' => new ReviewResource($review->load(self::RELATIONS)),
        ]);
    }

    /**
     * Admin moderation. Hides or restores a review; never deletes one, and never on
     * the grounds that a business dislikes it.
     */
    public function moderate(Request $request, Review $review): JsonResponse
    {
        $this->authorize('moderate', $review);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(array_column(ReviewStatus::cases(), 'value'))],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $review = $this->reviews->moderate(
            $review,
            $request->user(),
            ReviewStatus::from($validated['status']),
            $validated['note'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Review moderated.',
            'data' => new ReviewResource($review->load(self::RELATIONS)),
        ]);
    }
}
