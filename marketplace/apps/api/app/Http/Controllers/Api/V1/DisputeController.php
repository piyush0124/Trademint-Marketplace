<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DisputeStatus;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Order;
use App\Policies\OrderPolicy;
use App\Services\DisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 10 — disputes for both parties, with admin resolution.
 *
 * A dispute can only be raised on an order the caller is party to, and only an
 * admin can decide it. The two rules together mean neither side can weaponise the
 * process against the other.
 */
class DisputeController extends Controller
{
    public function __construct(private readonly DisputeService $disputes) {}

    /** The caller's disputes; unscoped for admins. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Dispute::query()->with(DisputeService::RELATIONS);

        if ($user->isCustomer()) {
            $query->where('customer_id', $user->id);
        } elseif ($user->isOwner()) {
            $business = $user->business;
            $business === null ? $query->whereRaw('1 = 0') : $query->where('business_id', $business->id);
        }

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $disputes = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Disputes retrieved successfully.',
            'data' => collect($disputes->items())->map(fn (Dispute $dispute) => $this->present($dispute, $request))->all(),
            'meta' => [
                'current_page' => $disputes->currentPage(),
                'per_page' => $disputes->perPage(),
                'total' => $disputes->total(),
                'last_page' => $disputes->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Dispute $dispute): JsonResponse
    {
        abort_unless(
            $request->user()->isAdmin() || $dispute->partyFor($request->user()) !== null,
            403,
            'You do not have permission to perform this action.'
        );

        return response()->json([
            'success' => true,
            'message' => 'Dispute retrieved successfully.',
            'data' => $this->present($dispute->load(DisputeService::RELATIONS), $request),
        ]);
    }

    /** Raise a dispute on an order. Either party, never a stranger. */
    public function store(Request $request, Order $order): JsonResponse
    {
        // Being a party to the order is the entire authorisation.
        $this->authorize('view', $order);

        $party = app(OrderPolicy::class)->partyFor($request->user(), $order);

        abort_if($party === null, 403, 'Only the customer or business on this order can raise a dispute.');

        $validated = $request->validate([
            'reason' => ['required', 'string', Rule::in(Dispute::REASONS)],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'evidence' => ['nullable', 'string', 'max:5000'],
        ]);

        $dispute = $this->disputes->raise(
            $order,
            $request->user(),
            $party,
            $validated['reason'],
            $validated['description'],
            $validated['evidence'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => sprintf('Dispute %s raised. An administrator will review it.', $dispute->reference),
            'data' => $this->present($dispute, $request),
        ], 201);
    }

    /** Admin-only resolution. */
    public function updateStatus(Request $request, Dispute $dispute): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'You do not have permission to perform this action.');

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(array_column(DisputeStatus::cases(), 'value'))],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
            'resolution' => ['nullable', 'string', 'max:5000'],
        ]);

        $dispute = $this->disputes->updateStatus(
            $dispute,
            $request->user(),
            DisputeStatus::from($validated['status']),
            $validated['admin_notes'] ?? null,
            $validated['resolution'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Dispute updated.',
            'data' => $this->present($dispute, $request),
        ]);
    }

    /**
     * Presented inline rather than through a resource class because the shape is
     * small and admin-only fields are the only variation.
     */
    private function present(Dispute $dispute, Request $request): array
    {
        $isAdmin = $request->user()->isAdmin();

        return [
            'id' => $dispute->uuid,
            'type' => 'dispute',
            'attributes' => [
                'reference' => $dispute->reference,
                'reason' => $dispute->reason,
                'description' => $dispute->description,
                'evidence' => $dispute->evidence,
                'status' => $dispute->status->value,
                'status_label' => $dispute->status->label(),
                'raised_by_party' => $dispute->raised_by_party,
                'resolution' => $dispute->resolution,
                // Internal notes are for staff only.
                'admin_notes' => $isAdmin ? $dispute->admin_notes : null,
                'resolved_at' => $dispute->resolved_at?->toIso8601String(),
                'created_at' => $dispute->created_at?->toIso8601String(),
                'is_active' => $dispute->status->isActive(),
            ],
            'relationships' => [
                'order' => $dispute->relationLoaded('order') && $dispute->order !== null
                    ? ['id' => $dispute->order->uuid, 'order_number' => $dispute->order->order_number, 'total_amount' => $dispute->order->total_amount, 'currency' => $dispute->order->currency]
                    : null,
                'business' => $dispute->relationLoaded('business') && $dispute->business !== null
                    ? ['id' => $dispute->business->slug, 'name' => $dispute->business->name]
                    : null,
            ],
        ];
    }
}
