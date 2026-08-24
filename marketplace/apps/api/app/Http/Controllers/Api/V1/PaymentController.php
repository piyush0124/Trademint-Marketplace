<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePaymentRequest;
use App\Http\Requests\Api\V1\StoreRefundRequest;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\RefundResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8 — payments for both parties.
 *
 * The customer starts a payment; the business confirms offline receipt or issues a
 * refund. No endpoint here accepts an amount or a status from the client: the
 * amount comes from the order, and settlement comes from a verified provider event
 * or an authorised confirmation.
 */
class PaymentController extends Controller
{
    private const RELATIONS = ['order.items', 'refunds', 'business.location'];

    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    /** Payment methods on offer. Labels and keys only — never a secret. */
    public function methods(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Payment methods retrieved successfully.',
            'data' => $this->gateways->available(),
        ]);
    }

    /** The caller's own payments, scoped by role. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $user = $request->user();
        $query = Payment::query()->with(self::RELATIONS);

        if ($user->isCustomer()) {
            $query->forCustomer($user->id);
        } elseif ($user->isOwner()) {
            $business = $user->business;

            if ($business === null) {
                return response()->json([
                    'success' => true,
                    'message' => 'Create your business profile to start receiving payments.',
                    'data' => [],
                    'meta' => ['current_page' => 1, 'per_page' => 0, 'total' => 0, 'last_page' => 1, 'status_counts' => $this->emptyCounts()],
                ]);
            }

            $query->forBusiness($business->id);
        }
        // Admin: unscoped, for reconciliation.

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        if ($request->filled('filter.order')) {
            $query->whereHas('order', fn ($q) => $q->where('uuid', $request->input('filter.order')));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $payments = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Payments retrieved successfully.',
            'data' => PaymentResource::collection($payments->items()),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
                'last_page' => $payments->lastPage(),
                'status_counts' => $this->statusCounts($request),
            ],
        ]);
    }

    public function show(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        return response()->json([
            'success' => true,
            'message' => 'Payment retrieved successfully.',
            'data' => new PaymentResource($payment->load(self::RELATIONS)),
        ]);
    }

    /**
     * Start a payment on an order. Only the order's own customer may pay.
     *
     * Idempotent: repeating the request with the same key, or while an attempt is
     * still in flight, returns that attempt rather than creating another.
     */
    public function store(StorePaymentRequest $request, Order $order): JsonResponse
    {
        $this->authorize('pay', $order);

        $payment = $this->payments->initiate(
            $order,
            $request->user(),
            $request->validated('provider'),
            $request->validated('idempotency_key'),
        );

        return response()->json([
            'success' => true,
            'message' => $payment->status === PaymentStatus::Paid
                ? 'This payment has already been settled.'
                : 'Payment initiated.',
            'data' => new PaymentResource($payment->load(self::RELATIONS)),
        ], 201);
    }

    /**
     * Confirm an offline payment.
     *
     * The manual-settlement equivalent of a webhook, restricted to the receiving
     * business or an admin — never the payer.
     */
    public function confirm(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('confirm', $payment);

        $validated = $request->validate(['reference' => ['nullable', 'string', 'max:100']]);

        $payment = $this->payments->confirmManually($payment, $request->user(), $validated['reference'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Payment confirmed.',
            'data' => new PaymentResource($payment->load(self::RELATIONS)),
        ]);
    }

    /** Full or partial refund, by the receiving business or an admin. */
    public function refund(StoreRefundRequest $request, Payment $payment): JsonResponse
    {
        $this->authorize('refund', $payment);

        $refund = $this->payments->refund(
            $payment,
            $request->user(),
            (string) $request->validated('amount'),
            $request->validated('reason'),
        );

        return response()->json([
            'success' => true,
            'message' => sprintf('Refund %s recorded.', $refund->refund_reference),
            'data' => new RefundResource($refund),
        ], 201);
    }

    /** Payments against one order, for the order screen. */
    public function indexForOrder(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        $payments = $order->payments()->with(self::RELATIONS)->latest()->get();

        return response()->json([
            'success' => true,
            'message' => 'Payments retrieved successfully.',
            'data' => PaymentResource::collection($payments),
            'meta' => [
                'total' => $payments->count(),
                'amount_due' => $order->amountDue(),
                'currency' => $order->currency,
                'is_payable' => $order->isPayable(),
                'methods' => $this->gateways->available(),
            ],
        ]);
    }

    private function statusCounts(Request $request): array
    {
        $user = $request->user();
        $query = Payment::query();

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

        return collect(PaymentStatus::cases())
            ->mapWithKeys(fn (PaymentStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)])
            ->all();
    }

    private function emptyCounts(): array
    {
        return collect(PaymentStatus::cases())
            ->mapWithKeys(fn (PaymentStatus $status) => [$status->value => 0])
            ->all();
    }
}
