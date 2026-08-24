<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Quote;
use App\Policies\OrderPolicy;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 7 — orders, for both parties.
 *
 * Orders are normally created automatically when a quote is accepted (see
 * CreateOrderFromAcceptedQuote). The explicit create endpoint exists as an
 * idempotent retry for the rare case where that listener failed, not as a second
 * way to place an order.
 */
class OrderController extends Controller
{
    private const RELATIONS = [
        'items',
        'business.location',
        'business.category',
        'requirement.category',
        'requirement.location',
        'quote',
        'statusHistory',
        // Phase 9/10: eager-loaded so `can_review` / `active_dispute` cost one
        // query for a whole page of orders instead of one per row.
        'review.business',
        'disputes',
    ];

    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderPolicy $policy,
    ) {}

    /** The caller's own orders, scoped by role. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $user = $request->user();
        $query = Order::query()->with(self::RELATIONS);

        if ($user->isCustomer()) {
            $query->forCustomer($user->id);
        } elseif ($user->isOwner()) {
            $business = $user->business;

            if ($business === null) {
                return response()->json([
                    'success' => true,
                    'message' => 'Create your business profile to start receiving orders.',
                    'data' => [],
                    'meta' => ['current_page' => 1, 'per_page' => 0, 'total' => 0, 'last_page' => 1, 'status_counts' => $this->emptyCounts()],
                ]);
            }

            $query->forBusiness($business->id);
        }
        // Admin: unscoped, for moderation.

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        if ($request->filled('filter.payment_status')) {
            $query->where('payment_status', $request->input('filter.payment_status'));
        }

        if ($request->boolean('filter.open')) {
            $query->open();
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $orders = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Orders retrieved successfully.',
            'data' => OrderResource::collection($orders->items()),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
                'status_counts' => $this->statusCounts($request),
            ],
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return response()->json([
            'success' => true,
            'message' => 'Order retrieved successfully.',
            'data' => new OrderResource($order->load(self::RELATIONS)),
        ]);
    }

    /**
     * Idempotent retry of order creation for an accepted quote.
     *
     * Authorised as a decision on the quote: only the customer who accepted it (or
     * an admin) may ask for the order to be materialised.
     */
    public function storeFromQuote(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize('decide', $quote);

        $order = $this->orders->createFromQuote($quote);

        return response()->json([
            'success' => true,
            'message' => sprintf('Order %s is ready.', $order->order_number),
            'data' => new OrderResource($order->load(self::RELATIONS)),
        ], 201);
    }

    /** Progress the order. The acting party is derived, never sent. */
    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        $this->authorize('transition', $order);

        $party = $this->policy->partyFor($request->user(), $order) ?? ($request->user()->isAdmin() ? 'admin' : null);

        abort_if($party === null, 403, 'You are not a party to this order.');

        $order = $this->orders->transition(
            $order,
            OrderStatus::from($request->validated('status')),
            $request->user(),
            $party,
            $request->validated('note'),
        );

        return response()->json([
            'success' => true,
            'message' => sprintf('Order is now %s.', strtolower($order->status->label())),
            'data' => new OrderResource($order->load(self::RELATIONS)),
        ]);
    }

    /** Each side may keep its own notes on a live order. */
    public function updateNotes(Request $request, Order $order): JsonResponse
    {
        $this->authorize('annotate', $order);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:5000']]);
        $party = $this->policy->partyFor($request->user(), $order);

        abort_if($party === null, 403, 'You are not a party to this order.');

        $order = $this->orders->updateNotes($order, $party, $validated['note'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Notes updated.',
            'data' => new OrderResource($order->load(self::RELATIONS)),
        ]);
    }

    private function statusCounts(Request $request): array
    {
        $user = $request->user();
        $query = Order::query();

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

        return collect(OrderStatus::cases())
            ->mapWithKeys(fn (OrderStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)])
            ->all();
    }

    private function emptyCounts(): array
    {
        return collect(OrderStatus::cases())
            ->mapWithKeys(fn (OrderStatus $status) => [$status->value => 0])
            ->all();
    }
}
