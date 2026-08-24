<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreRequirementRequest;
use App\Http\Requests\Api\V1\UpdateRequirementRequest;
use App\Http\Resources\RequirementResource;
use App\Models\Requirement;
use App\Services\RequirementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RequirementController extends Controller
{
    private const RELATIONS = ['category', 'location', 'customer'];

    public function __construct(private readonly RequirementService $requirementService) {}

    /** The authenticated customer's own requirements, across every status. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Requirement::class);

        $query = $request->user()
            ->requirements()
            ->with(self::RELATIONS);

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $requirements = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Requirements retrieved successfully.',
            'data' => RequirementResource::collection($requirements->items()),
            'meta' => [
                'current_page' => $requirements->currentPage(),
                'per_page' => $requirements->perPage(),
                'total' => $requirements->total(),
                'last_page' => $requirements->lastPage(),
                'status_counts' => $this->statusCounts($request->user()->id),
            ],
        ]);
    }

    /**
     * Requirements visible to businesses browsing the marketplace — only
     * ever the publicly-visible statuses (never a draft), and never
     * exposing which customer raised them. No matching/ranking logic here;
     * that belongs to a later phase.
     */
    public function indexPublic(Request $request): JsonResponse
    {
        abort_unless($request->user()->isOwner(), 403, 'You do not have permission to perform this action.');

        $query = Requirement::query()
            ->with(['category', 'location'])
            ->publiclyVisible();

        if ($request->filled('filter.category')) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $request->input('filter.category')));
        }

        if ($request->filled('filter.city')) {
            $query->whereHas('location', fn ($q) => $q->where('city', 'like', '%'.$request->input('filter.city').'%'));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $requirements = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Requirements retrieved successfully.',
            'data' => RequirementResource::collection($requirements->items()),
            'meta' => [
                'current_page' => $requirements->currentPage(),
                'per_page' => $requirements->perPage(),
                'total' => $requirements->total(),
                'last_page' => $requirements->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Requirement $requirement): JsonResponse
    {
        $this->authorize('view', $requirement);

        $requirement->load(self::RELATIONS);

        return response()->json([
            'success' => true,
            'message' => 'Requirement retrieved successfully.',
            'data' => new RequirementResource($requirement),
        ]);
    }

    /** Always creates a DRAFT — never auto-publishes. */
    public function store(StoreRequirementRequest $request): JsonResponse
    {
        $this->authorize('create', Requirement::class);

        $requirement = $this->requirementService->createDraft($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Draft requirement created.',
            'data' => new RequirementResource($requirement),
        ], 201);
    }

    /** Only allowed while the requirement is still a draft. */
    public function update(UpdateRequirementRequest $request, Requirement $requirement): JsonResponse
    {
        $this->authorize('update', $requirement);

        $requirement = $this->requirementService->updateDraft($requirement, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Requirement updated.',
            'data' => new RequirementResource($requirement),
        ]);
    }

    public function publish(Requirement $requirement): JsonResponse
    {
        $this->authorize('publish', $requirement);

        $requirement = $this->requirementService->publish($requirement);

        return response()->json([
            'success' => true,
            'message' => 'Requirement published. The marketplace will start finding suitable businesses.',
            'data' => new RequirementResource($requirement),
        ]);
    }

    public function cancel(Requirement $requirement): JsonResponse
    {
        $this->authorize('cancel', $requirement);

        $requirement = $this->requirementService->cancel($requirement);

        return response()->json([
            'success' => true,
            'message' => 'Requirement cancelled.',
            'data' => new RequirementResource($requirement),
        ]);
    }

    private function statusCounts(int $customerId): array
    {
        $counts = Requirement::query()
            ->where('customer_id', $customerId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'draft' => (int) ($counts['draft'] ?? 0),
            'published' => (int) ($counts['published'] ?? 0),
            'matching' => (int) ($counts['matching'] ?? 0),
            'receiving_quotes' => (int) ($counts['receiving_quotes'] ?? 0),
            'closed' => (int) ($counts['closed'] ?? 0),
            'cancelled' => (int) ($counts['cancelled'] ?? 0),
        ];
    }
}
