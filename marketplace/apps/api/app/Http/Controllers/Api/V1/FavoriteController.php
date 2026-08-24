<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Favorite;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $listings = Listing::query()
            ->whereHas('favorites', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with(['category', 'location', 'media'])
            ->published()
            ->latest()
            ->paginate(min((int) $request->input('per_page', 20), 100));

        return response()->json([
            'success' => true,
            'message' => 'Favorites retrieved successfully.',
            'data' => ListingResource::collection($listings->items()),
            'meta' => [
                'current_page' => $listings->currentPage(),
                'per_page' => $listings->perPage(),
                'total' => $listings->total(),
                'last_page' => $listings->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, Listing $listing): JsonResponse
    {
        Favorite::firstOrCreate([
            'user_id' => $request->user()->id,
            'listing_id' => $listing->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Listing saved to favorites.',
            'data' => ['favorited' => true],
        ], 201);
    }

    public function destroy(Request $request, Listing $listing): JsonResponse
    {
        Favorite::where('user_id', $request->user()->id)->where('listing_id', $listing->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Listing removed from favorites.',
            'data' => ['favorited' => false],
        ]);
    }
}
