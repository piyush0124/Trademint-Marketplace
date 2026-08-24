<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EnquiryStatus;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RespondToEnquiryRequest;
use App\Http\Requests\Api\V1\StoreEnquiryRequest;
use App\Http\Resources\EnquiryResource;
use App\Http\Resources\QuoteResource;
use App\Models\Enquiry;
use App\Models\Requirement;
use App\Models\RequirementMatch;
use App\Services\EnquiryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 5 — enquiries, for both parties.
 *
 * One controller serves the customer and the business because the record is the
 * same conversation; what differs is scope, and that is applied here (each list
 * is filtered to the caller's own rows) and enforced again by EnquiryPolicy on
 * every single-record action.
 *
 * Nothing is ever read from the request to establish identity: the customer is
 * the authenticated user, and the business is the authenticated owner's own
 * business record.
 */
class EnquiryController extends Controller
{
    private const RELATIONS = ['requirement.category', 'requirement.location', 'business.location', 'business.category'];

    public function __construct(private readonly EnquiryService $enquiryService) {}

    /**
     * Customer opens an enquiry with a business that was matched to their
     * requirement.
     *
     * Authorised against the requirement (not the enquiry) because the enquiry
     * does not exist yet — `viewMatches` is exactly the right test: only the
     * requirement's own customer may act on its matches.
     */
    public function store(StoreEnquiryRequest $request, Requirement $requirement): JsonResponse
    {
        $this->authorize('viewMatches', $requirement);

        // The business is derived from the match, so a client cannot enquire on
        // behalf of, or direct an enquiry at, an arbitrary business.
        $match = RequirementMatch::query()
            ->where('uuid', $request->validated('match_id'))
            ->firstOrFail();

        $enquiry = $this->enquiryService->openFromCustomer($requirement, $match, $request->validated('message'));

        return response()->json([
            'success' => true,
            'message' => 'Enquiry sent to the business.',
            'data' => new EnquiryResource($enquiry->load(self::RELATIONS)),
        ], 201);
    }

    /** The caller's own enquiries: their side of every conversation they are in. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Enquiry::class);

        $user = $request->user();
        $query = Enquiry::query()->with(self::RELATIONS)->withCount('quotes');

        if ($user->isCustomer()) {
            $query->forCustomer($user->id);
        } elseif ($user->isOwner()) {
            $business = $user->business;

            if ($business === null) {
                return $this->emptyCollection('Create your business profile to start receiving enquiries.');
            }

            $query->forBusiness($business->id);
        }
        // An admin is scoped to nothing on purpose — moderation needs the whole
        // picture. Everything else about the response is identical.

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        if ($request->filled('filter.requirement')) {
            $query->whereHas('requirement', fn (Builder $q) => $q->where('uuid', $request->input('filter.requirement')));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $enquiries = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Enquiries retrieved successfully.',
            'data' => EnquiryResource::collection($enquiries->items()),
            'meta' => [
                'current_page' => $enquiries->currentPage(),
                'per_page' => $enquiries->perPage(),
                'total' => $enquiries->total(),
                'last_page' => $enquiries->lastPage(),
                'status_counts' => $this->statusCounts($request),
            ],
        ]);
    }

    public function show(Request $request, Enquiry $enquiry): JsonResponse
    {
        $this->authorize('view', $enquiry);

        $enquiry->load([...self::RELATIONS, 'match', 'customer']);
        $enquiry->loadCount('quotes');

        return response()->json([
            'success' => true,
            'message' => 'Enquiry retrieved successfully.',
            'data' => new EnquiryResource($enquiry),
        ]);
    }

    /** Business agrees to engage. Unlocks quoting for this enquiry only. */
    public function accept(RespondToEnquiryRequest $request, Enquiry $enquiry): JsonResponse
    {
        $this->authorize('respond', $enquiry);

        $enquiry = $this->enquiryService->accept($enquiry, $request->validated('message'));

        return response()->json([
            'success' => true,
            'message' => 'Enquiry accepted. You can now prepare a quotation.',
            'data' => new EnquiryResource($enquiry->load(self::RELATIONS)),
        ]);
    }

    public function decline(RespondToEnquiryRequest $request, Enquiry $enquiry): JsonResponse
    {
        $this->authorize('respond', $enquiry);

        $enquiry = $this->enquiryService->decline($enquiry, $request->validated('message'));

        return response()->json([
            'success' => true,
            'message' => 'Enquiry declined. The customer has been notified.',
            'data' => new EnquiryResource($enquiry->load(self::RELATIONS)),
        ]);
    }

    /** Customer withdraws an enquiry the business has not answered. */
    public function cancel(Enquiry $enquiry): JsonResponse
    {
        $this->authorize('cancel', $enquiry);

        $enquiry = $this->enquiryService->cancel($enquiry);

        return response()->json([
            'success' => true,
            'message' => 'Enquiry cancelled.',
            'data' => new EnquiryResource($enquiry->load(self::RELATIONS)),
        ]);
    }

    /** Either party concludes the conversation. */
    public function close(Enquiry $enquiry): JsonResponse
    {
        $this->authorize('close', $enquiry);

        $enquiry = $this->enquiryService->close($enquiry);

        return response()->json([
            'success' => true,
            'message' => 'Enquiry closed.',
            'data' => new EnquiryResource($enquiry->load(self::RELATIONS)),
        ]);
    }

    /**
     * Quotes on this enquiry.
     *
     * A draft is the business's private working document, so the customer's view
     * excludes drafts — the same rule QuotePolicy::view enforces for a single
     * quote, applied to the collection.
     */
    public function quotes(Request $request, Enquiry $enquiry): JsonResponse
    {
        $this->authorize('view', $enquiry);

        $isBusiness = $enquiry->business !== null && $enquiry->business->user_id === $request->user()->id;

        $quotes = $enquiry->quotes()
            ->with(['items', 'business.location'])
            ->when(! $isBusiness && ! $request->user()->isAdmin(), fn (Builder $q) => $q->where('status', '!=', QuoteStatus::Draft->value))
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Quotes retrieved successfully.',
            'data' => QuoteResource::collection($quotes),
            'meta' => ['total' => $quotes->count()],
        ]);
    }

    /** Counts for the caller's own enquiries, for dashboard tabs. */
    private function statusCounts(Request $request): array
    {
        $user = $request->user();

        $query = Enquiry::query();

        if ($user->isCustomer()) {
            $query->forCustomer($user->id);
        } elseif ($user->isOwner()) {
            $business = $user->business;
            if ($business === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->forBusiness($business->id);
            }
        }

        $counts = $query->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(EnquiryStatus::cases())
            ->mapWithKeys(fn (EnquiryStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)])
            ->all();
    }

    private function emptyCollection(string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [],
            'meta' => [
                'current_page' => 1,
                'per_page' => 0,
                'total' => 0,
                'last_page' => 1,
                'status_counts' => collect(EnquiryStatus::cases())
                    ->mapWithKeys(fn (EnquiryStatus $status) => [$status->value => 0])
                    ->all(),
            ],
        ]);
    }
}
