<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLeadRequest;
use App\Http\Requests\Api\V1\UpdateLeadStatusRequest;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    /** Public enquiry submission — logged in or not. Only published listings can be enquired about. */
    public function store(StoreLeadRequest $request, Listing $listing): JsonResponse
    {
        if (! $listing->isPublished()) {
            return response()->json([
                'success' => false,
                'message' => 'This listing is not currently accepting enquiries.',
                'errors' => [],
            ], 422);
        }

        $lead = Lead::create([
            'listing_id' => $listing->id,
            'user_id' => $request->user('sanctum')?->id,
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'phone' => $request->validated('phone'),
            'message' => $request->validated('message'),
            'status' => LeadStatus::New,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Your enquiry has been sent to the owner.',
            'data' => new LeadResource($lead->load('listing')),
        ], 201);
    }

    /** The authenticated owner's leads, across every listing they own. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $query = Lead::query()
            ->whereHas('listing', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with(['listing.category', 'listing.location', 'listing.media', 'listing.attributeValues.definition']);

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        if ($request->filled('filter.listing')) {
            $query->where('listing_id', $request->input('filter.listing'));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $leads = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Leads retrieved successfully.',
            'data' => LeadResource::collection($leads->items()),
            'meta' => [
                'current_page' => $leads->currentPage(),
                'per_page' => $leads->perPage(),
                'total' => $leads->total(),
                'last_page' => $leads->lastPage(),
                'status_counts' => $this->statusCounts($request->user()->id),
            ],
        ]);
    }

    public function updateStatus(UpdateLeadStatusRequest $request, Lead $lead): JsonResponse
    {
        $this->authorize('update', $lead);

        $status = LeadStatus::from($request->validated('status'));

        $lead->status = $status;
        if ($status === LeadStatus::Contacted && $lead->contacted_at === null) {
            $lead->contacted_at = now();
        }
        $lead->save();

        return response()->json([
            'success' => true,
            'message' => 'Lead status updated.',
            'data' => new LeadResource($lead->fresh(['listing'])),
        ]);
    }

    private function statusCounts(int $ownerId): array
    {
        $counts = Lead::query()
            ->whereHas('listing', fn ($q) => $q->where('user_id', $ownerId))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'new' => (int) ($counts['new'] ?? 0),
            'contacted' => (int) ($counts['contacted'] ?? 0),
            'converted' => (int) ($counts['converted'] ?? 0),
            'lost' => (int) ($counts['lost'] ?? 0),
        ];
    }
}
