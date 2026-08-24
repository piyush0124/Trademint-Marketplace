<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RejectListingRequest;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Services\ListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminListingController extends Controller
{
    public function __construct(private readonly ListingService $listingService) {}

    /** Moderation queue. Defaults to pending review; filterable to any status. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Listing::class);

        $perPage = min((int) $request->input('per_page', 20), 100);
        $status = $request->input('filter.status', 'pending_review');

        $query = Listing::query()->with(['category', 'location', 'media', 'user', 'business']);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $listings = $query->orderBy('submitted_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Listings retrieved successfully.',
            'data' => ListingResource::collection($listings->items()),
            'meta' => [
                'current_page' => $listings->currentPage(),
                'per_page' => $listings->perPage(),
                'total' => $listings->total(),
                'last_page' => $listings->lastPage(),
            ],
        ]);
    }

    public function show(Listing $listing): JsonResponse
    {
        $this->authorize('moderate', $listing);

        $listing->load(['category', 'location', 'attributeValues.definition', 'media', 'user', 'business']);

        return response()->json([
            'success' => true,
            'message' => 'Listing retrieved successfully.',
            'data' => new ListingResource($listing),
        ]);
    }

    public function approve(Listing $listing): JsonResponse
    {
        $this->authorize('moderate', $listing);

        $listing = $this->listingService->approve($listing);

        return response()->json([
            'success' => true,
            'message' => 'Listing approved and published.',
            'data' => new ListingResource($listing),
        ]);
    }

    public function reject(RejectListingRequest $request, Listing $listing): JsonResponse
    {
        $this->authorize('moderate', $listing);

        $listing = $this->listingService->reject($listing, $request->validated('reason'));

        return response()->json([
            'success' => true,
            'message' => 'Listing rejected.',
            'data' => new ListingResource($listing),
        ]);
    }

    public function archive(Listing $listing): JsonResponse
    {
        $this->authorize('moderate', $listing);

        $listing = $this->listingService->archive($listing);

        return response()->json([
            'success' => true,
            'message' => 'Listing archived.',
            'data' => new ListingResource($listing),
        ]);
    }
}
