<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RejectQuoteRequest;
use App\Http\Requests\Api\V1\StoreQuoteRequest;
use App\Http\Requests\Api\V1\UpdateQuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Models\Enquiry;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 5 — quotations.
 *
 * The business writes (create / edit draft / send / withdraw), the customer
 * decides (accept / reject). Every action is authorised by QuotePolicy, and the
 * commercial rules — only accepted enquiries can be quoted, only drafts can be
 * edited, expiry, single acceptance — live in QuoteService, not here.
 *
 * No monetary value in any request body is ever persisted; the service
 * recalculates from the line inputs. That is deliberate and is the reason this
 * controller does no arithmetic at all.
 */
class QuoteController extends Controller
{
    private const RELATIONS = [
        'items',
        'business.location',
        'business.category',
        'requirement.category',
        'requirement.location',
        // Phase 6: the live offer and the negotiation, so a client can seed a
        // counter form and continue an existing thread.
        'currentRevision.items',
        'negotiation',
    ];

    public function __construct(private readonly QuoteService $quoteService) {}

    /** The caller's own quotes: issued (business) or received (customer). */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Quote::class);

        $user = $request->user();
        $query = Quote::query()->with(self::RELATIONS);

        if ($user->isCustomer()) {
            // A customer never sees another business's unsent working document.
            $query->forCustomer($user->id)->where('status', '!=', QuoteStatus::Draft->value);
        } elseif ($user->isOwner()) {
            $business = $user->business;

            if ($business === null) {
                return $this->emptyCollection('Create your business profile to start issuing quotes.');
            }

            $query->forBusiness($business->id);
        }
        // Admin: unscoped, for moderation.

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        if ($request->filled('filter.enquiry')) {
            $query->whereHas('enquiry', fn (Builder $q) => $q->where('uuid', $request->input('filter.enquiry')));
        }

        if ($request->filled('filter.requirement')) {
            $query->whereHas('requirement', fn (Builder $q) => $q->where('uuid', $request->input('filter.requirement')));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $quotes = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Quotes retrieved successfully.',
            'data' => QuoteResource::collection($quotes->items()),
            'meta' => [
                'current_page' => $quotes->currentPage(),
                'per_page' => $quotes->perPage(),
                'total' => $quotes->total(),
                'last_page' => $quotes->lastPage(),
                'status_counts' => $this->statusCounts($request),
            ],
        ]);
    }

    /** Business drafts a quote against an enquiry it has accepted. */
    public function store(StoreQuoteRequest $request, Enquiry $enquiry): JsonResponse
    {
        $this->authorize('createQuote', $enquiry);

        $quote = $this->quoteService->createDraft($enquiry, $request->validated());

        return response()->json([
            'success' => true,
            'message' => sprintf('Draft quote %s created.', $quote->quote_number),
            'data' => new QuoteResource($quote->load(self::RELATIONS)),
        ], 201);
    }

    /**
     * A single quote. When the receiving customer opens a sent quote, that view
     * is recorded — and a quote whose validity has lapsed in the meantime is
     * settled as expired at the same moment, so nobody is shown a live quote
     * they can no longer accept.
     */
    public function show(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize('view', $quote);

        if ($request->user()->id === $quote->customer_id) {
            $quote = $this->quoteService->markViewedByCustomer($quote);
        } else {
            $this->quoteService->expireIfLapsed($quote);
        }

        return response()->json([
            'success' => true,
            'message' => 'Quote retrieved successfully.',
            'data' => new QuoteResource($quote->fresh(self::RELATIONS)),
        ]);
    }

    /** Edit a draft. Registered for both PUT and PATCH; partial payloads are fine. */
    public function update(UpdateQuoteRequest $request, Quote $quote): JsonResponse
    {
        $this->authorize('update', $quote);

        $quote = $this->quoteService->updateDraft($quote, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Quote updated.',
            'data' => new QuoteResource($quote->load(self::RELATIONS)),
        ]);
    }

    /** Only a draft can be deleted; a sent quote is a commercial record. */
    public function destroy(Quote $quote): JsonResponse
    {
        $this->authorize('delete', $quote);

        $this->quoteService->deleteDraft($quote);

        return response()->json([
            'success' => true,
            'message' => 'Draft quote deleted.',
            'data' => null,
        ]);
    }

    public function send(Quote $quote): JsonResponse
    {
        $this->authorize('send', $quote);

        $quote = $this->quoteService->send($quote);

        return response()->json([
            'success' => true,
            'message' => sprintf('Quote %s sent to the customer.', $quote->quote_number),
            'data' => new QuoteResource($quote->load(self::RELATIONS)),
        ]);
    }

    /**
     * Customer accepts. Records the decision only — Phase 7 turns an accepted
     * quote into an order by listening for the QuoteAccepted event.
     */
    public function accept(Quote $quote): JsonResponse
    {
        $this->authorize('decide', $quote);

        $quote = $this->quoteService->accept($quote);

        return response()->json([
            'success' => true,
            'message' => 'Quote accepted.',
            'data' => new QuoteResource($quote->load(self::RELATIONS)),
        ]);
    }

    public function reject(RejectQuoteRequest $request, Quote $quote): JsonResponse
    {
        $this->authorize('decide', $quote);

        $quote = $this->quoteService->reject($quote, $request->validated('reason'));

        return response()->json([
            'success' => true,
            'message' => 'Quote rejected.',
            'data' => new QuoteResource($quote->load(self::RELATIONS)),
        ]);
    }

    /** Business pulls back a sent quote before the customer decides. */
    public function withdraw(Quote $quote): JsonResponse
    {
        $this->authorize('withdraw', $quote);

        $quote = $this->quoteService->withdraw($quote);

        return response()->json([
            'success' => true,
            'message' => 'Quote withdrawn.',
            'data' => new QuoteResource($quote->load(self::RELATIONS)),
        ]);
    }

    private function statusCounts(Request $request): array
    {
        $user = $request->user();
        $query = Quote::query();

        if ($user->isCustomer()) {
            $query->forCustomer($user->id)->where('status', '!=', QuoteStatus::Draft->value);
        } elseif ($user->isOwner()) {
            $business = $user->business;
            if ($business === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->forBusiness($business->id);
            }
        }

        $counts = $query->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(QuoteStatus::cases())
            ->mapWithKeys(fn (QuoteStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)])
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
                'status_counts' => collect(QuoteStatus::cases())
                    ->mapWithKeys(fn (QuoteStatus $status) => [$status->value => 0])
                    ->all(),
            ],
        ]);
    }
}
