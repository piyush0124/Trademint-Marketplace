<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\ReviewResource;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\Requirement;
use App\Models\Review;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 10 — admin moderation surfaces.
 *
 * Every method starts with the same role assertion, and every *action* (as opposed
 * to read) writes an audit entry naming the admin. Admin power in this codebase is
 * broad but never silent.
 *
 * The monitoring endpoints are read-only summaries. An admin can see the platform's
 * commercial activity for moderation and dispute handling, but there is deliberately
 * no endpoint here that lets an admin act commercially on a party's behalf —
 * accepting a quote, paying an order or answering a negotiation remain impossible
 * for staff, as the earlier phases' policies enforce.
 */
class AdminModerationController extends Controller
{
    public function __construct(
        private readonly VerificationService $verification,
        private readonly AuditLogger $audit,
    ) {}

    /** Every entry point re-asserts the role; there is no admin middleware to forget. */
    private function assertAdmin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403, 'You do not have permission to perform this action.');
    }

    /* ---------------------------------------------------------------- Users */

    public function users(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $query = User::query()->withCount(['requirements', 'enquiries']);

        if ($request->filled('filter.role')) {
            $query->where('role', $request->input('filter.role'));
        }

        if ($request->filled('filter.search')) {
            $search = trim((string) $request->input('filter.search'));
            $query->where(fn ($q) => $q->where('name', 'like', "%$search%")->orWhere('email', 'like', "%$search%"));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $users = $query->latest()->paginate($perPage);

        return $this->paginated('Users retrieved successfully.', UserResource::collection($users->items()), $users);
    }

    /* ----------------------------------------------------------- Businesses */

    public function businesses(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $query = Business::query()->with(['location', 'category'])->withCount(['orders', 'reviews', 'documents']);

        if ($request->filled('filter.verification_status')) {
            $query->where('verification_status', $request->input('filter.verification_status'));
        }

        if ($request->filled('filter.search')) {
            $search = trim((string) $request->input('filter.search'));
            $query->where('name', 'like', "%$search%");
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $businesses = $query->latest()->paginate($perPage);

        return $this->paginated('Businesses retrieved successfully.', BusinessResource::collection($businesses->items()), $businesses, [
            'verification_counts' => collect(VerificationStatus::cases())
                ->mapWithKeys(fn (VerificationStatus $status) => [
                    $status->value => Business::where('verification_status', $status->value)->count(),
                ])->all(),
        ]);
    }

    /**
     * Grant, reject, suspend or reinstate verification.
     *
     * The single most consequential admin action in the app, so it is audited with
     * the target, the outcome and the reason.
     */
    public function updateVerification(Request $request, Business $business): JsonResponse
    {
        $this->assertAdmin($request);

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['approve', 'reject', 'suspend', 'reinstate'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $reason = $validated['reason'] ?? null;

        // Rejection and suspension must be explained — a business is entitled to
        // know why.
        if (in_array($validated['action'], ['reject', 'suspend'], true) && empty($reason)) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => ['reason' => ['A reason is required when rejecting or suspending a business.']],
            ], 422);
        }

        $business = match ($validated['action']) {
            'approve' => $this->verification->approve($business, $request->user(), $reason),
            'reject' => $this->verification->reject($business, $request->user(), $reason),
            'suspend' => $this->verification->suspend($business, $request->user(), $reason),
            'reinstate' => $this->verification->reinstate($business, $request->user(), $reason),
        };

        $this->audit->log('business.verification.'.$validated['action'], $business, $request->user(), [
            'reason' => $reason,
            'status' => $business->verification_status,
        ]);

        return response()->json([
            'success' => true,
            'message' => sprintf('Business verification %sd.', $validated['action'] === 'approve' ? 'approve' : $validated['action']),
            'data' => new BusinessResource($business->load(['location', 'category'])),
        ]);
    }

    /** Documents pending review, with no file contents — download is separate. */
    public function documents(Request $request, Business $business): JsonResponse
    {
        $this->assertAdmin($request);

        return response()->json([
            'success' => true,
            'message' => 'Documents retrieved successfully.',
            'data' => $business->documents()->latest()->get()->map(fn (BusinessDocument $document) => [
                'id' => $document->uuid,
                'type' => $document->type,
                'title' => $document->title,
                'status' => $document->status,
                'file_mime' => $document->file_mime,
                'file_size' => $document->file_size,
                'review_notes' => $document->review_notes,
                'uploaded_at' => $document->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function reviewDocument(Request $request, BusinessDocument $document): JsonResponse
    {
        $this->assertAdmin($request);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([BusinessDocument::STATUS_APPROVED, BusinessDocument::STATUS_REJECTED])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $document = $this->verification->reviewDocument($document, $request->user(), $validated['status'], $validated['notes'] ?? null);

        $this->audit->log('business.document.'.$validated['status'], $document, $request->user(), ['type' => $document->type]);

        return response()->json([
            'success' => true,
            'message' => 'Document reviewed.',
            'data' => ['id' => $document->uuid, 'status' => $document->status],
        ]);
    }

    /* ------------------------------------------------------- Monitoring ---- */

    /** Platform overview. Counts only — no personal data. */
    public function dashboard(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        return response()->json([
            'success' => true,
            'message' => 'Admin dashboard retrieved successfully.',
            'data' => [
                'users' => [
                    'total' => User::count(),
                    'customers' => User::where('role', 'customer')->count(),
                    'owners' => User::where('role', 'owner')->count(),
                ],
                'businesses' => [
                    'total' => Business::count(),
                    'verified' => Business::where('verification_status', VerificationStatus::Verified->value)->count(),
                    'pending' => Business::where('verification_status', VerificationStatus::Pending->value)->count(),
                    'suspended' => Business::where('verification_status', VerificationStatus::Suspended->value)->count(),
                ],
                'marketplace' => [
                    'requirements' => Requirement::count(),
                    'quotes' => Quote::count(),
                    'orders' => Order::count(),
                    'completed_orders' => Order::where('status', OrderStatus::Completed->value)->count(),
                    'reviews' => Review::count(),
                ],
                'money' => [
                    // Settled payments only, so the figure means something.
                    'settled_payments' => Payment::settled()->count(),
                    'paid_total' => (string) Payment::settled()->sum('amount'),
                    'refunded_total' => (string) Payment::sum('amount_refunded'),
                    // The reporting currency, from config rather than assumed by the
                    // client. These totals are a naive sum: if the platform ever
                    // trades in more than one currency they must be grouped by it.
                    'currency' => config('marketplace.default_currency'),
                    'currencies_in_use' => Payment::query()->distinct()->pluck('currency')->values()->all(),
                ],
                'attention' => [
                    'pending_verifications' => Business::where('verification_status', VerificationStatus::Pending->value)->count(),
                    'open_disputes' => Dispute::active()->count(),
                    'flagged_reviews' => Review::where('status', 'flagged')->count(),
                    'failed_payments' => Payment::where('status', PaymentStatus::Failed->value)->count(),
                ],
            ],
        ]);
    }

    /** Read-only commercial monitoring. */
    public function orders(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $query = Order::query()->with(['business', 'requirement']);

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $orders = $query->latest()->paginate($perPage);

        return $this->paginated('Orders retrieved successfully.', OrderResource::collection($orders->items()), $orders);
    }

    public function payments(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $query = Payment::query()->with(['order', 'business']);

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $payments = $query->latest()->paginate($perPage);

        return $this->paginated('Payments retrieved successfully.', PaymentResource::collection($payments->items()), $payments);
    }

    public function reviews(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $query = Review::query()->with(['business', 'customer', 'order']);

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $reviews = $query->latest()->paginate($perPage);

        return $this->paginated('Reviews retrieved successfully.', ReviewResource::collection($reviews->items()), $reviews);
    }

    public function disputes(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $query = Dispute::query()->with(['order', 'business']);

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $disputes = $query->latest()->paginate($perPage);

        return $this->paginated('Disputes retrieved successfully.', collect($disputes->items())->map(fn (Dispute $dispute) => [
            'id' => $dispute->uuid,
            'reference' => $dispute->reference,
            'status' => $dispute->status->value,
            'reason' => $dispute->reason,
            'raised_by_party' => $dispute->raised_by_party,
            'order_number' => $dispute->order?->order_number,
            'business_name' => $dispute->business?->name,
            'created_at' => $dispute->created_at?->toIso8601String(),
        ])->all(), $disputes, [
            'status_counts' => collect(DisputeStatus::cases())
                ->mapWithKeys(fn (DisputeStatus $status) => [$status->value => Dispute::where('status', $status->value)->count()])
                ->all(),
        ]);
    }

    /** The audit trail. Read-only by construction — the model forbids writes. */
    public function auditLogs(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $query = AuditLog::query()->with('user');

        if ($request->filled('filter.action')) {
            $query->where('action', 'like', $request->input('filter.action').'%');
        }

        if ($request->filled('filter.entity_type')) {
            $query->where('entity_type', $request->input('filter.entity_type'));
        }

        $perPage = min((int) $request->input('per_page', 50), 200);
        $logs = $query->latest()->paginate($perPage);

        return $this->paginated('Audit log retrieved successfully.', collect($logs->items())->map(fn ($log) => [
            'id' => $log->id,
            'action' => $log->action,
            'actor' => $log->user?->name,
            'actor_role' => $log->actor_role,
            'entity_type' => $log->entity_type,
            'entity_reference' => $log->entity_reference,
            'ip_address' => $log->ip_address,
            'metadata' => $log->metadata,
            'created_at' => $log->created_at?->toIso8601String(),
        ])->all(), $logs);
    }

    private function paginated(string $message, mixed $data, mixed $paginator, array $extraMeta = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => array_merge([
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ], $extraMeta),
        ]);
    }
}
