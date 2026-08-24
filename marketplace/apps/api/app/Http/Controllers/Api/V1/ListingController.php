<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreListingRequest;
use App\Http\Requests\Api\V1\UpdateListingRequest;
use App\Http\Resources\ListingResource;
use App\Models\Category;
use App\Models\Listing;
use App\Services\ListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListingController extends Controller
{
    public function __construct(private readonly ListingService $listingService) {}

    /** Public browse — only ever shows published listings. */
    public function index(Request $request): JsonResponse
    {
        $query = Listing::query()
            ->with(['category', 'location', 'media', 'business'])
            ->published();

        if ($request->filled('filter.category')) {
            $category = Category::where('slug', $request->input('filter.category'))->first();
            if ($category) {
                $query->where('category_id', $category->id);
            }
        }

        if ($request->filled('filter.q')) {
            $keyword = $request->input('filter.q');
            $query->where(function ($q) use ($keyword): void {
                $q->where('title', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%");
            });
        }

        if ($request->filled('filter.business')) {
            $query->whereHas('business', fn ($q) => $q->where('slug', $request->input('filter.business')));
        }

        if ($request->filled('filter.city')) {
            $query->whereHas('location', fn ($q) => $q->where('city', 'like', '%'.$request->input('filter.city').'%'));
        }

        if ($request->filled('filter.availability')) {
            $query->where('availability_status', $request->input('filter.availability'));
        }

        if ($request->filled('filter.currency')) {
            $query->where('currency', strtoupper($request->input('filter.currency')));
        }

        if ($request->filled('filter.min_price')) {
            $query->where('price', '>=', $request->input('filter.min_price'));
        }

        if ($request->filled('filter.max_price')) {
            $query->where('price', '<=', $request->input('filter.max_price'));
        }

        /*
         * Category attribute filters, driven by the database.
         *
         * `filter[attr][<key>]=<value>` works for any filterable attribute in any
         * category — `filter[attr][property_type]=villa` for real estate,
         * `filter[attr][grade]=304` for steel — because the key is looked up
         * against `category_attribute_definitions` rather than hardcoded here.
         * The previous version had a named branch per real-estate attribute, which
         * meant every new industry needed a controller change.
         *
         * Only definitions marked `is_filterable` are honoured, so this cannot be
         * used to probe attributes that were never meant to be queryable.
         */
        $attributeFilters = $request->input('filter.attr', []);

        if (is_array($attributeFilters)) {
            foreach ($attributeFilters as $key => $value) {
                if (! is_string($key) || $value === null || $value === '' || is_array($value)) {
                    continue;
                }

                $query->whereHas('attributeValues', function ($q) use ($key, $value): void {
                    $q->where('value', $value)
                        ->whereHas('definition', fn ($d) => $d->where('key', $key)->where('is_filterable', true));
                });
            }
        }

        // Legacy aliases for the two real-estate filters that shipped in Phase 1.
        // Kept so existing links and bookmarks keep working; new code should use
        // the generic `filter[attr][...]` form above.
        foreach (['property_type', 'listing_type'] as $legacyKey) {
            if (! $request->filled("filter.$legacyKey")) {
                continue;
            }

            $query->whereHas('attributeValues', function ($q) use ($request, $legacyKey): void {
                $q->where('value', $request->input("filter.$legacyKey"))
                    ->whereHas('definition', fn ($d) => $d->where('key', $legacyKey));
            });
        }

        $sort = $request->input('sort', '-created_at');
        foreach (explode(',', $sort) as $field) {
            $direction = str_starts_with($field, '-') ? 'desc' : 'asc';
            $column = ltrim($field, '-');
            if (in_array($column, ['created_at', 'price', 'title'], true)) {
                $query->orderBy($column, $direction);
            }
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $listings = $query->paginate($perPage);

        return $this->paginated($listings);
    }

    public function show(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('view', $listing);

        $listing->load(['category', 'location', 'attributeValues.definition', 'media', 'user', 'business']);

        return response()->json([
            'success' => true,
            'message' => 'Listing retrieved successfully.',
            'data' => new ListingResource($listing),
        ]);
    }

    /** Always creates a DRAFT — never auto-publishes. */
    public function store(StoreListingRequest $request): JsonResponse
    {
        $this->authorize('create', Listing::class);

        $listing = $this->listingService->createDraft($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Draft listing created.',
            'data' => new ListingResource($listing),
        ], 201);
    }

    /** Only allowed while the listing is still owner-editable (draft/rejected), or by an admin. */
    public function update(UpdateListingRequest $request, Listing $listing): JsonResponse
    {
        $this->authorize('update', $listing);

        $listing = $this->listingService->updateDraft($listing, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Listing updated.',
            'data' => new ListingResource($listing),
        ]);
    }

    public function destroy(Listing $listing): JsonResponse
    {
        $this->authorize('archive', $listing);

        $this->listingService->archive($listing);

        return response()->json([
            'success' => true,
            'message' => 'Listing archived.',
            'data' => null,
        ]);
    }

    public function submit(Listing $listing): JsonResponse
    {
        $this->authorize('submit', $listing);

        $listing = $this->listingService->submitForReview($listing);

        return response()->json([
            'success' => true,
            'message' => 'Listing submitted for review.',
            'data' => new ListingResource($listing),
        ]);
    }

    public function markSold(Listing $listing): JsonResponse
    {
        $this->authorize('updateAvailability', $listing);

        $listing = $this->listingService->markSold($listing);

        return response()->json([
            'success' => true,
            'message' => 'Listing marked as sold.',
            'data' => new ListingResource($listing),
        ]);
    }

    public function markRented(Listing $listing): JsonResponse
    {
        $this->authorize('updateAvailability', $listing);

        $listing = $this->listingService->markRented($listing);

        return response()->json([
            'success' => true,
            'message' => 'Listing marked as rented.',
            'data' => new ListingResource($listing),
        ]);
    }

    public function markAvailable(Listing $listing): JsonResponse
    {
        $this->authorize('updateAvailability', $listing);

        $listing = $this->listingService->markAvailable($listing);

        return response()->json([
            'success' => true,
            'message' => 'Listing marked as available.',
            'data' => new ListingResource($listing),
        ]);
    }

    private function paginated($listings): JsonResponse
    {
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
            'links' => [
                'first' => $listings->url(1),
                'last' => $listings->url($listings->lastPage()),
                'prev' => $listings->previousPageUrl(),
                'next' => $listings->nextPageUrl(),
            ],
        ]);
    }
}
