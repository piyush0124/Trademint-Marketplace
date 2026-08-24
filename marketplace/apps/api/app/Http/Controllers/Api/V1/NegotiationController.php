<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NegotiationParty;
use App\Enums\NegotiationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RespondToNegotiationRequest;
use App\Http\Requests\Api\V1\StoreCounterOfferRequest;
use App\Http\Requests\Api\V1\StoreNegotiationMessageRequest;
use App\Http\Resources\NegotiationMessageResource;
use App\Http\Resources\NegotiationResource;
use App\Models\Negotiation;
use App\Models\Quote;
use App\Services\NegotiationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 6 — negotiation endpoints, shared by both parties.
 *
 * The controller establishes *who is acting* from the authenticated user and the
 * record itself (`Negotiation::partyFor`), never from the request body — so a
 * customer cannot claim to be acting as the business. Everything else (turn,
 * expiry, concurrency, money) is enforced by NegotiationService.
 */
class NegotiationController extends Controller
{
    public function __construct(private readonly NegotiationService $negotiations) {}

    /** The caller's negotiations, scoped by role. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Negotiation::class);

        $user = $request->user();
        $query = Negotiation::query()->with([
            'quote.items',
            'requirement.category',
            'requirement.location',
            'business.location',
            'currentRevision.items',
        ]);

        if ($user->isCustomer()) {
            $query->forCustomer($user->id);
        } elseif ($user->isOwner()) {
            $business = $user->business;

            if ($business === null) {
                return response()->json([
                    'success' => true,
                    'message' => 'Create your business profile to start negotiating.',
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

        // "Waiting on me" is the single most useful filter on a negotiation list.
        if ($request->boolean('filter.awaiting_me') && ! $user->isAdmin()) {
            $query->active()->where('awaiting_party', $user->isCustomer() ? 'customer' : 'business');
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $negotiations = $query->orderByDesc('last_activity_at')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Negotiations retrieved successfully.',
            'data' => NegotiationResource::collection($negotiations->items()),
            'meta' => [
                'current_page' => $negotiations->currentPage(),
                'per_page' => $negotiations->perPage(),
                'total' => $negotiations->total(),
                'last_page' => $negotiations->lastPage(),
                'status_counts' => $this->statusCounts($request),
            ],
        ]);
    }

    /**
     * One negotiation with its full immutable history and timeline.
     *
     * Reading also settles a lapsed offer, so the screen can never present an
     * "Accept" button for terms that have already expired.
     */
    public function show(Request $request, Negotiation $negotiation): JsonResponse
    {
        $this->authorize('view', $negotiation);

        $this->negotiations->expireIfLapsed($negotiation);

        return response()->json([
            'success' => true,
            'message' => 'Negotiation retrieved successfully.',
            'data' => new NegotiationResource(
                $negotiation->fresh(array_merge(NegotiationService::RELATIONS, ['revisions.items', 'business.location']))
            ),
        ]);
    }

    /**
     * Make a counter offer against a quote. Opens the negotiation on the first
     * counter; appends an immutable revision every time.
     */
    public function counter(StoreCounterOfferRequest $request, Quote $quote): JsonResponse
    {
        $party = $this->partyForQuote($request, $quote);

        $negotiation = $this->negotiations->counter($quote, $request->user(), $party, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Counter offer sent.',
            'data' => new NegotiationResource($negotiation),
        ], 201);
    }

    public function accept(RespondToNegotiationRequest $request, Negotiation $negotiation): JsonResponse
    {
        $this->authorize('participate', $negotiation);
        $party = $negotiation->partyFor($request->user());

        $negotiation = $this->negotiations->accept(
            $negotiation->quote,
            $request->user(),
            $party,
            $request->validated('revision_id'),
            $request->validated('note'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Offer accepted.',
            'data' => new NegotiationResource($negotiation),
        ]);
    }

    public function reject(RespondToNegotiationRequest $request, Negotiation $negotiation): JsonResponse
    {
        $this->authorize('participate', $negotiation);
        $party = $negotiation->partyFor($request->user());

        $negotiation = $this->negotiations->reject(
            $negotiation->quote,
            $request->user(),
            $party,
            $request->validated('note'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Offer rejected.',
            'data' => new NegotiationResource($negotiation),
        ]);
    }

    /** Only the party that made the live offer may pull it back. */
    public function withdraw(RespondToNegotiationRequest $request, Negotiation $negotiation): JsonResponse
    {
        $this->authorize('participate', $negotiation);
        $party = $negotiation->partyFor($request->user());

        $negotiation = $this->negotiations->withdraw(
            $negotiation->quote,
            $request->user(),
            $party,
            $request->validated('note'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Offer withdrawn.',
            'data' => new NegotiationResource($negotiation),
        ]);
    }

    public function message(StoreNegotiationMessageRequest $request, Negotiation $negotiation): JsonResponse
    {
        $this->authorize('participate', $negotiation);
        $party = $negotiation->partyFor($request->user());

        $entry = $this->negotiations->message($negotiation, $request->user(), $party, $request->validated('body'));

        return response()->json([
            'success' => true,
            'message' => 'Message added to the negotiation.',
            'data' => new NegotiationMessageResource($entry),
        ], 201);
    }

    /**
     * Establish the acting party for a quote-level action (the first counter,
     * before a negotiation row exists).
     *
     * Authorises against the quote's own participants — so a stranger gets a 403
     * here rather than reaching the service at all.
     */
    private function partyForQuote(Request $request, Quote $quote): NegotiationParty
    {
        $user = $request->user();

        if ($user->id === $quote->customer_id) {
            return NegotiationParty::Customer;
        }

        $quote->loadMissing('business');

        if ($quote->business !== null && $quote->business->user_id === $user->id) {
            return NegotiationParty::Business;
        }

        abort(403, 'You are not a party to this quote.');
    }

    private function statusCounts(Request $request): array
    {
        $user = $request->user();
        $query = Negotiation::query();

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

        return collect(NegotiationStatus::cases())
            ->mapWithKeys(fn (NegotiationStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)])
            ->all();
    }

    private function emptyCounts(): array
    {
        return collect(NegotiationStatus::cases())
            ->mapWithKeys(fn (NegotiationStatus $status) => [$status->value => 0])
            ->all();
    }
}
