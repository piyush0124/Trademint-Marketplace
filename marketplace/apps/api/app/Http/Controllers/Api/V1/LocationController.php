<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ListingStatus;
use App\Http\Controllers\Controller;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    /**
     * Cities that actually have published listings, most-listed first.
     * Deliberately data-driven — no hardcoded city array. New cities
     * appear here automatically as soon as an Owner publishes a listing
     * there; nothing to change in this file or the frontend.
     */
    public function popular(): JsonResponse
    {
        $cities = Listing::query()
            ->join('locations', 'locations.id', '=', 'listings.location_id')
            ->where('listings.status', ListingStatus::Published->value)
            ->selectRaw('locations.city as city, count(*) as listings_count')
            ->groupBy('locations.city')
            ->orderByDesc('listings_count')
            ->limit(12)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Popular locations retrieved.',
            'data' => $cities->map(fn ($row) => [
                'city' => $row->city,
                'listings_count' => (int) $row->listings_count,
            ]),
        ]);
    }
}
