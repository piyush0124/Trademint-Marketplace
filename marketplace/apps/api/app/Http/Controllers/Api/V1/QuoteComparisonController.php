<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Models\Quote;
use App\Models\Requirement;
use App\Services\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Side-by-side comparison of every quote received for one requirement
 * (Phase 6, extended for multi-vendor B2B in Phase 10).
 *
 * Restricted to the requirement's own customer: a business must never see what
 * its competitors quoted. That is the same rule as the match list, for the same
 * reason.
 *
 * The comparison is computed server-side so every business is measured the same
 * way, and so "cheapest" cannot be spoofed by a client reordering the list. It is
 * deliberately dimension-agnostic — price, timing, terms and trust — because a
 * warehouse build and a month of digital marketing have to be comparable through
 * the same lens.
 */
class QuoteComparisonController extends Controller
{
    public function __construct(private readonly QuoteService $quotes) {}

    public function index(Request $request, Requirement $requirement): JsonResponse
    {
        // Same ability as the match list: customer-only, never the businesses.
        $this->authorize('viewMatches', $requirement);

        $quotes = Quote::query()
            ->where('requirement_id', $requirement->id)
            // Drafts are private to the business and must never appear here.
            ->where('status', '!=', QuoteStatus::Draft->value)
            ->with(['items', 'business.location', 'business.category', 'currentRevision', 'negotiation'])
            ->get();

        // Settle any that lapsed while sitting in the list, so the comparison
        // never invites acceptance of an expired quote.
        $quotes->each(fn (Quote $quote) => $this->quotes->expireIfLapsed($quote));

        $comparable = $quotes->filter(fn (Quote $quote) => $quote->status !== QuoteStatus::Draft);

        return response()->json([
            'success' => true,
            'message' => $comparable->isEmpty()
                ? 'No quotes have been received for this requirement yet.'
                : 'Quotes retrieved for comparison.',
            'data' => QuoteResource::collection($comparable->values()),
            'meta' => [
                'total' => $comparable->count(),
                'requirement' => [
                    'id' => $requirement->uuid,
                    'title' => $requirement->title,
                    'quantity' => $requirement->quantity,
                    'unit' => $requirement->unit,
                    'budget_min' => $requirement->budget_min,
                    'budget_max' => $requirement->budget_max,
                    'required_date' => $requirement->required_date?->toDateString(),
                ],
                'comparison' => $this->summarise($comparable),
            ],
        ]);
    }

    /**
     * The comparison axes, each resolved to the winning quote id where a winner
     * is meaningful. Null when the data does not support a comparison — a
     * service quote with no delivery date should not be reported as "slowest".
     *
     * @param  Collection<int, Quote>  $quotes
     */
    private function summarise(Collection $quotes): array
    {
        if ($quotes->isEmpty()) {
            return [
                'lowest_total_quote_id' => null,
                'highest_total_quote_id' => null,
                'fastest_quote_id' => null,
                'best_rated_quote_id' => null,
                'currencies' => [],
                'totals' => [],
            ];
        }

        // Comparing across currencies would be dishonest without an FX rate, so
        // the response reports which currencies are present and the frontend
        // warns rather than pretending they are interchangeable.
        $currencies = $quotes->pluck('currency')->unique()->values()->all();
        $singleCurrency = count($currencies) === 1;

        $byTotal = $quotes->sortBy(fn (Quote $quote) => (float) $quote->total_amount);

        // Earliest promised completion, taking whichever of the two date fields a
        // quote actually uses — products tend to have delivery, services
        // completion, and neither is assumed.
        $withDates = $quotes->filter(fn (Quote $quote) => $quote->estimated_delivery_date !== null || $quote->estimated_completion_date !== null);
        $fastest = $withDates->sortBy(function (Quote $quote) {
            $dates = array_filter([$quote->estimated_delivery_date, $quote->estimated_completion_date]);
            usort($dates, fn ($a, $b) => $a <=> $b);

            return $dates[0]->timestamp;
        })->first();

        $rated = $quotes->filter(fn (Quote $quote) => $quote->business?->rating !== null);
        $bestRated = $rated->sortByDesc(fn (Quote $quote) => (float) $quote->business->rating)->first();

        return [
            'lowest_total_quote_id' => $singleCurrency ? $byTotal->first()?->uuid : null,
            'highest_total_quote_id' => $singleCurrency ? $byTotal->last()?->uuid : null,
            'fastest_quote_id' => $fastest?->uuid,
            'best_rated_quote_id' => $bestRated?->uuid,
            'currencies' => $currencies,
            'comparable_on_price' => $singleCurrency,
            // Per-quote comparison row, so the frontend renders a table without
            // deriving anything itself.
            'totals' => $quotes->map(fn (Quote $quote) => [
                'quote_id' => $quote->uuid,
                'quote_number' => $quote->quote_number,
                'business_name' => $quote->business?->name,
                'business_rating' => $quote->business?->rating,
                'business_is_verified' => (bool) $quote->business?->is_verified,
                'total_amount' => $quote->total_amount,
                'currency' => $quote->currency,
                'status' => $quote->status->value,
                'estimated_delivery_date' => $quote->estimated_delivery_date?->toDateString(),
                'estimated_completion_date' => $quote->estimated_completion_date?->toDateString(),
                'validity_date' => $quote->validity_date?->toDateString(),
                'is_expired' => $quote->hasExpired(),
                'warranty_terms' => $quote->warranty_terms,
                'payment_terms' => $quote->payment_terms,
                'revision_count' => (int) $quote->revision_count,
                'is_negotiating' => $quote->negotiation?->isActive() ?? false,
                'within_budget' => $this->withinBudget($quote),
            ])->values()->all(),
        ];
    }

    /** Whether the quote lands inside the customer's stated budget, if they gave one. */
    private function withinBudget(Quote $quote): ?bool
    {
        $requirement = $quote->requirement ?? $quote->requirement()->first();

        if ($requirement === null || ($requirement->budget_min === null && $requirement->budget_max === null)) {
            return null;
        }

        $total = (float) $quote->total_amount;
        $min = $requirement->budget_min !== null ? (float) $requirement->budget_min : 0.0;
        $max = $requirement->budget_max !== null ? (float) $requirement->budget_max : INF;

        return $total >= $min && $total <= $max;
    }
}
