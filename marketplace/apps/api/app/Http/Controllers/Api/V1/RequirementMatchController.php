<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MatchResponseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AskRequirementMatchQuestionRequest;
use App\Http\Requests\Api\V1\DeclineRequirementMatchRequest;
use App\Http\Resources\RequirementMatchResource;
use App\Models\Requirement;
use App\Models\RequirementMatch;
use App\Services\MatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 4 endpoints for both sides of a match.
 *
 * Customer side: the ranked businesses found for one of their requirements,
 * plus a way to re-run the engine.
 *
 * Business side: the requirements it has been invited to, and the actions it
 * can take on one (view / accept / decline / ask a question). Quotations are
 * deliberately absent — accepting expresses interest, nothing more.
 *
 * Every action authorises through a policy; the controller itself only
 * translates HTTP to and from MatchingService.
 */
class RequirementMatchController extends Controller
{
    /** Loaded for the customer's view — the business and everything shown about it. */
    private const BUSINESS_RELATIONS = ['business.location', 'business.category', 'business.industries', 'business.serviceAreas'];

    /** Loaded for the business's view — the requirement being offered to it. */
    private const REQUIREMENT_RELATIONS = ['requirement.category', 'requirement.location'];

    public function __construct(private readonly MatchingService $matchingService) {}

    /**
     * Suitable businesses for one requirement, best match first.
     *
     * Restricted to the requirement's own customer (see
     * RequirementPolicy::viewMatches) — a business must not be able to read
     * the full match list and learn who it is competing against.
     */
    public function indexForRequirement(Request $request, Requirement $requirement): JsonResponse
    {
        $this->authorize('viewMatches', $requirement);

        $matches = $this->matchingService->matchesFor($requirement);

        return response()->json([
            'success' => true,
            'message' => $matches->isEmpty()
                ? 'No suitable businesses have been matched to this requirement yet.'
                : 'Matched businesses retrieved successfully.',
            'data' => RequirementMatchResource::collection($matches),
            'meta' => [
                'total' => $matches->count(),
                'requirement_status' => $requirement->status->value,
                'response_counts' => $this->responseCounts($requirement),
                'weights' => config('marketplace.matching.weights'),
                'minimum_score' => (float) config('marketplace.matching.minimum_score'),
            ],
        ]);
    }

    /**
     * Re-run the engine for a requirement. Matching already runs
     * automatically on publish; this exists so a customer can refresh after
     * new businesses join or an existing one extends its capabilities.
     * Existing invitations keep their invited/viewed/response state.
     */
    public function generate(Requirement $requirement): JsonResponse
    {
        $this->authorize('generateMatches', $requirement);

        $matches = $this->matchingService->generateMatches($requirement);

        return response()->json([
            'success' => true,
            'message' => $matches->isEmpty()
                ? 'No businesses currently meet the matching threshold for this requirement.'
                : sprintf('%d suitable business(es) matched.', $matches->count()),
            'data' => RequirementMatchResource::collection($matches),
            'meta' => [
                'total' => $matches->count(),
                'requirement_status' => $requirement->fresh()->status->value,
            ],
        ]);
    }

    /**
     * The authenticated business's matched requirements — its Phase 4 inbox.
     *
     * Scoped by business_id, so a business can only ever see requirements it
     * was actually invited to, never the open marketplace browse.
     */
    public function indexForBusiness(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RequirementMatch::class);

        $business = $request->user()->business;

        if ($business === null) {
            return response()->json([
                'success' => true,
                'message' => 'Create your business profile to start receiving matched requirements.',
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 0,
                    'total' => 0,
                    'last_page' => 1,
                    'response_counts' => ['pending' => 0, 'accepted' => 0, 'declined' => 0],
                ],
            ]);
        }

        $query = RequirementMatch::query()
            ->forBusiness($business->id)
            ->whereHas('requirement', fn ($q) => $q->publiclyVisible())
            ->with([...self::REQUIREMENT_RELATIONS, 'business']);

        if ($request->filled('filter.response_status')) {
            $query->where('response_status', $request->input('filter.response_status'));
        }

        if ($request->filled('filter.category')) {
            $query->whereHas('requirement.category', fn ($q) => $q->where('slug', $request->input('filter.category')));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $matches = $query->ranked()->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Matched requirements retrieved successfully.',
            'data' => RequirementMatchResource::collection($matches->items()),
            'meta' => [
                'current_page' => $matches->currentPage(),
                'per_page' => $matches->perPage(),
                'total' => $matches->total(),
                'last_page' => $matches->lastPage(),
                'response_counts' => $this->businessResponseCounts($business->id),
            ],
        ]);
    }

    /**
     * A single match. When the invited business opens it, that first view is
     * recorded — the customer can then see the business has actually looked.
     */
    public function show(Request $request, RequirementMatch $match): JsonResponse
    {
        $this->authorize('view', $match);

        $match->load([...self::REQUIREMENT_RELATIONS, ...self::BUSINESS_RELATIONS]);

        if ($request->user()->can('markViewed', $match)) {
            $this->matchingService->markViewed($match);
        }

        return response()->json([
            'success' => true,
            'message' => 'Match retrieved successfully.',
            'data' => new RequirementMatchResource($match),
        ]);
    }

    public function accept(RequirementMatch $match): JsonResponse
    {
        $this->authorize('respond', $match);

        $match = $this->matchingService->accept($match);

        return response()->json([
            'success' => true,
            'message' => 'You have accepted this requirement. The customer can now see your interest.',
            'data' => new RequirementMatchResource($match->load([...self::REQUIREMENT_RELATIONS, 'business'])),
        ]);
    }

    public function decline(DeclineRequirementMatchRequest $request, RequirementMatch $match): JsonResponse
    {
        $this->authorize('respond', $match);

        $match = $this->matchingService->decline($match, $request->validated('note'));

        return response()->json([
            'success' => true,
            'message' => 'You have declined this requirement.',
            'data' => new RequirementMatchResource($match->load([...self::REQUIREMENT_RELATIONS, 'business'])),
        ]);
    }

    public function question(AskRequirementMatchQuestionRequest $request, RequirementMatch $match): JsonResponse
    {
        $this->authorize('respond', $match);

        $match = $this->matchingService->askQuestion($match, $request->validated('question'));

        return response()->json([
            'success' => true,
            'message' => 'Your question has been sent to the customer.',
            'data' => new RequirementMatchResource($match->load([...self::REQUIREMENT_RELATIONS, 'business'])),
        ]);
    }

    /** How the invited businesses have responded, for the customer's summary. */
    private function responseCounts(Requirement $requirement): array
    {
        $counts = $requirement->matches()
            ->selectRaw('response_status, count(*) as total')
            ->groupBy('response_status')
            ->pluck('total', 'response_status');

        return $this->normaliseCounts($counts->all());
    }

    private function businessResponseCounts(int $businessId): array
    {
        $counts = RequirementMatch::query()
            ->forBusiness($businessId)
            ->whereHas('requirement', fn ($q) => $q->publiclyVisible())
            ->selectRaw('response_status, count(*) as total')
            ->groupBy('response_status')
            ->pluck('total', 'response_status');

        return $this->normaliseCounts($counts->all());
    }

    private function normaliseCounts(array $counts): array
    {
        return [
            MatchResponseStatus::Pending->value => (int) ($counts[MatchResponseStatus::Pending->value] ?? 0),
            MatchResponseStatus::Accepted->value => (int) ($counts[MatchResponseStatus::Accepted->value] ?? 0),
            MatchResponseStatus::Declined->value => (int) ($counts[MatchResponseStatus::Declined->value] ?? 0),
        ];
    }
}
