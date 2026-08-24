<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserListingController extends Controller
{
    /** The authenticated owner's own listings, across every workflow status. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isOwner(), 403, 'You do not have permission to perform this action.');

        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = $request->user()
            ->listings()
            ->with(['category', 'location', 'attributeValues.definition', 'media', 'business'])
            ->withCount([
                'leads',
                'leads as new_leads_count' => fn ($q) => $q->where('status', 'new'),
            ]);

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        $listings = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Your listings retrieved successfully.',
            'data' => ListingResource::collection($listings->items()),
            'meta' => [
                'current_page' => $listings->currentPage(),
                'per_page' => $listings->perPage(),
                'total' => $listings->total(),
                'last_page' => $listings->lastPage(),
            ],
        ]);
    }
}
