<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBusinessRequest;
use App\Http\Requests\Api\V1\SyncBusinessIndustriesRequest;
use App\Http\Requests\Api\V1\SyncBusinessServiceAreasRequest;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use App\Models\Category;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Business profile management (Phase 2): core profile fields, service
 * areas, and industries served. Verification is handled separately by
 * BusinessVerificationController (admin-only — never self-service).
 */
class BusinessController extends Controller
{
    private const WITH_PUBLIC_RELATIONS = ['location', 'category', 'industries', 'serviceAreas'];

    /**
     * Public business discovery (extended in Phase 10).
     *
     * Filterable on the dimensions a buyer actually shops by — category (including
     * its subcategories), location or declared service area, verification, rating,
     * availability, business type and trade type — and sortable by rating, trust or
     * recency.
     *
     * Deliberately *not* verified-only any more: restricting discovery to verified
     * businesses made a new marketplace look empty. Verification is a filter and a
     * sort input instead, which is honest and still surfaces trust.
     *
     * No city, state or region appears in the code. Locations are matched by
     * normalised text against the database, so this works identically anywhere in
     * India.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Business::query()
            // A suspended business is never presented as a viable supplier.
            ->notSuspended()
            ->withCount(['listings' => fn ($q) => $q->published()])
            ->with(self::WITH_PUBLIC_RELATIONS);

        if ($request->filled('filter.category')) {
            $slug = $request->input('filter.category');
            $category = Category::where('slug', $slug)->first();

            if ($category !== null) {
                // Include subcategories so "IT & Software" finds a web developer.
                $ids = array_merge([$category->id], $category->children()->pluck('id')->all());

                $query->where(function ($q) use ($ids): void {
                    $q->whereIn('category_id', $ids)
                        ->orWhereHas('products', fn ($p) => $p->where('is_active', true)->whereIn('category_id', $ids))
                        ->orWhereHas('services', fn ($s) => $s->where('is_active', true)->whereIn('category_id', $ids))
                        ->orWhereHas('listings', fn ($l) => $l->published()->whereIn('category_id', $ids));
                });
            }
        }

        // Matches the business's own city *or* a declared service area, because a
        // supplier two districts away that delivers to you is still relevant.
        if ($request->filled('filter.city')) {
            $city = trim((string) $request->input('filter.city'));

            $query->where(function ($q) use ($city): void {
                $q->whereHas('location', fn ($l) => $l->where('city', 'like', "%$city%"))
                    ->orWhereHas('serviceAreas', fn ($l) => $l->where('city', 'like', "%$city%"));
            });
        }

        if ($request->filled('filter.state')) {
            $state = trim((string) $request->input('filter.state'));

            $query->where(function ($q) use ($state): void {
                $q->whereHas('location', fn ($l) => $l->where('state', 'like', "%$state%"))
                    ->orWhereHas('serviceAreas', fn ($l) => $l->where('state', 'like', "%$state%"));
            });
        }

        if ($request->boolean('filter.verified')) {
            $query->where('verification_status', VerificationStatus::Verified->value);
        }

        if ($request->filled('filter.min_rating')) {
            $query->where('rating', '>=', (float) $request->input('filter.min_rating'));
        }

        if ($request->filled('filter.business_type')) {
            $query->where('business_type', $request->input('filter.business_type'));
        }

        if ($request->filled('filter.trade_type')) {
            $query->where('trade_type', $request->input('filter.trade_type'));
        }

        if ($request->boolean('filter.available')) {
            $query->where('is_available', true);
        }

        if ($request->filled('filter.industry')) {
            $query->whereHas('industries', fn ($i) => $i->where('slug', $request->input('filter.industry')));
        }

        if ($request->filled('filter.search')) {
            $search = trim((string) $request->input('filter.search'));
            $query->where(fn ($q) => $q->where('name', 'like', "%$search%")->orWhere('about', 'like', "%$search%"));
        }

        // Ratings sort puts unrated businesses last rather than treating null as 0.
        match ($request->input('sort')) {
            'rating' => $query->orderByRaw('rating IS NULL')->orderByDesc('rating'),
            'reviews' => $query->orderByDesc('review_count'),
            'experience' => $query->orderByRaw('years_in_business IS NULL')->orderByDesc('years_in_business'),
            'recent' => $query->latest(),
            default => $query->orderByDesc('verification_status = \''.VerificationStatus::Verified->value.'\'')
                ->orderByRaw('rating IS NULL')
                ->orderByDesc('rating'),
        };

        $businesses = $query->paginate(min((int) $request->input('per_page', 12), 50));

        return response()->json([
            'success' => true,
            'message' => 'Businesses retrieved successfully.',
            'data' => BusinessResource::collection($businesses->items()),
            'meta' => [
                'current_page' => $businesses->currentPage(),
                'per_page' => $businesses->perPage(),
                'total' => $businesses->total(),
                'last_page' => $businesses->lastPage(),
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $business = Business::where('slug', $slug)
            ->withCount(['listings' => fn ($q) => $q->published()])
            ->with(self::WITH_PUBLIC_RELATIONS)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'message' => 'Business retrieved successfully.',
            'data' => new BusinessResource($business),
        ]);
    }

    /** The authenticated owner's own business profile, if any — used to prefill the settings form. */
    public function mine(Request $request): JsonResponse
    {
        abort_unless($request->user()->isOwner(), 403, 'You do not have permission to perform this action.');

        $business = $request->user()->business()->with(self::WITH_PUBLIC_RELATIONS)->first();

        return response()->json([
            'success' => true,
            'message' => 'Business profile retrieved successfully.',
            'data' => $business ? new BusinessResource($business) : null,
        ]);
    }

    public function storeOrUpdate(StoreBusinessRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isOwner(), 403, 'You do not have permission to perform this action.');

        $data = $request->validated();

        $location = null;
        if (! empty($data['location']['city'] ?? null)) {
            $location = Location::firstOrCreate([
                'country' => $data['location']['country'] ?? 'IN',
                'state' => $data['location']['state'] ?? null,
                'city' => $data['location']['city'],
                'area' => null,
                'locality' => null,
            ]);
        }

        $category = isset($data['category_slug'])
            ? Category::where('slug', $data['category_slug'])->where('is_active', true)->first()
            : null;

        $slug = $data['slug'] ?? Str::slug($data['name']);
        $existing = $user->business;

        $attributes = [
            'slug' => $slug,
            'name' => $data['name'],
            'about' => $data['about'] ?? null,
            'phone' => $data['phone'] ?? null,
            'whatsapp' => $data['whatsapp'] ?? null,
            'email' => $data['email'] ?? null,
            'website' => $data['website'] ?? null,
            'business_type' => $data['business_type'] ?? null,
            'years_in_business' => $data['years_in_business'] ?? null,
            'trade_type' => $data['trade_type'] ?? null,
            'business_hours' => $data['business_hours'] ?? null,
            'is_available' => $data['is_available'] ?? true,
            'location_id' => $location?->id ?? $existing?->location_id,
            'category_id' => $category?->id ?? $existing?->category_id,
        ];

        if (array_key_exists('gst_number', $data)) {
            $attributes['gst_number'] = $data['gst_number'];
            // A changed (or newly added) GST number invalidates any prior
            // verification of the old one — never carry a "verified" badge
            // over to a number that was never actually checked.
            if ($existing === null || $existing->gst_number !== $data['gst_number']) {
                $attributes['gst_verified_at'] = null;
            }
        }

        $business = Business::updateOrCreate(['user_id' => $user->id], $attributes);

        return response()->json([
            'success' => true,
            'message' => 'Business profile saved.',
            'data' => new BusinessResource($business->fresh(self::WITH_PUBLIC_RELATIONS)),
        ]);
    }

    /** Replace the business's full set of service-area locations in one call. */
    public function syncServiceAreas(SyncBusinessServiceAreasRequest $request): JsonResponse
    {
        $business = $request->user()->business;
        abort_if($business === null, 404, 'Create your business profile first.');

        $locationIds = collect($request->validated('locations'))->map(
            fn (array $loc) => Location::firstOrCreate([
                'country' => $loc['country'] ?? 'IN',
                'state' => $loc['state'] ?? null,
                'city' => $loc['city'],
                'area' => null,
                'locality' => null,
            ])->id
        );

        $business->serviceAreas()->sync($locationIds);

        return response()->json([
            'success' => true,
            'message' => 'Service areas updated.',
            'data' => new BusinessResource($business->fresh(self::WITH_PUBLIC_RELATIONS)),
        ]);
    }

    /** Replace the business's full set of selected industries in one call. */
    public function syncIndustries(SyncBusinessIndustriesRequest $request): JsonResponse
    {
        $business = $request->user()->business;
        abort_if($business === null, 404, 'Create your business profile first.');

        $business->industries()->sync($request->validated('industry_ids'));

        return response()->json([
            'success' => true,
            'message' => 'Industries updated.',
            'data' => new BusinessResource($business->fresh(self::WITH_PUBLIC_RELATIONS)),
        ]);
    }
}
