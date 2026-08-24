<?php

namespace App\Services\Ai\Intelligence;

use App\Exceptions\Ai\AiException;
use App\Models\Quote;
use App\Models\Requirement;
use App\Models\User;
use App\Services\Ai\AiService;
use Illuminate\Support\Collection;

/**
 * Phase 13 — a neutral comparison of quotes the buyer actually received.
 *
 * Two rules shape this, and both are refusals:
 *
 *  1. **No arithmetic by the model.** Cheapest, fastest, spread between highest and
 *     lowest — all computed here in PHP from stored decimals. A language model
 *     comparing "1,50,000" with "95,000" as strings is a real failure mode, and money
 *     is the wrong place to find out.
 *  2. **No recommendation.** The model describes trade-offs; it never picks a winner.
 *     Cheapest is often not best, the buyer knows things the marketplace does not, and
 *     an assistant that says "go with Supplier B" has quietly assumed liability for a
 *     commercial decision it cannot be accountable for.
 */
class QuoteComparisonSummariser
{
    public function __construct(private readonly AiService $ai) {}

    /**
     * @return array<string, mixed>
     */
    public function compare(Requirement $requirement, ?User $user = null): array
    {
        $quotes = $requirement->quotes()
            // Only offers the buyer can actually act on.
            ->whereIn('status', ['sent', 'viewed', 'accepted'])
            ->with(['business:id,name,slug,rating,review_count,verification_status,years_in_business', 'items'])
            ->get();

        if ($quotes->isEmpty()) {
            return [
                'requirement' => $requirement->title,
                'count' => 0,
                'quotes' => [],
                'facts' => [],
                'summary' => 'No quotes have been received on this requirement yet.',
                'used_ai' => false,
            ];
        }

        $rows = $this->rows($quotes);
        $facts = $this->facts($rows, $quotes);
        $summary = $this->narrate($requirement, $rows, $facts, $user);

        return [
            'requirement' => $requirement->title,
            'count' => $quotes->count(),
            'currency' => $quotes->first()->currency,
            'quotes' => $rows,
            // Server-computed comparisons, exposed separately so the UI can badge rows
            // without trusting prose.
            'facts' => $facts,
            'summary' => $summary ?? $this->deterministicSummary($rows, $facts),
            'used_ai' => $summary !== null,
            'note' => 'This is a comparison, not a recommendation. The choice is yours.',
        ];
    }

    /**
     * @param  Collection<int, Quote>  $quotes
     * @return array<int, array<string, mixed>>
     */
    private function rows(Collection $quotes): array
    {
        return $quotes->map(fn ($quote): array => [
            'id' => $quote->uuid,
            'quote_number' => $quote->quote_number,
            'supplier' => $quote->business?->name,
            'supplier_slug' => $quote->business?->slug,
            'supplier_verified' => $quote->business?->verificationStatus()->value === 'verified',
            'supplier_rating' => $quote->business?->rating !== null ? (float) $quote->business->rating : null,
            'supplier_review_count' => (int) ($quote->business?->review_count ?? 0),
            'supplier_years_in_business' => $quote->business?->years_in_business,
            'subtotal' => $quote->subtotal,
            'tax_amount' => $quote->tax_amount,
            'delivery_fee' => $quote->delivery_fee,
            'discount_amount' => $quote->discount_amount,
            'total_amount' => $quote->total_amount,
            'line_items' => $quote->items->count(),
            'estimated_delivery_date' => $quote->estimated_delivery_date?->toDateString(),
            'estimated_completion_date' => $quote->estimated_completion_date?->toDateString(),
            'validity_date' => $quote->validity_date?->toDateString(),
            'is_expired' => $quote->validity_date !== null && $quote->validity_date->isPast(),
            'payment_terms' => $quote->payment_terms,
            'warranty_terms' => $quote->warranty_terms,
            'cancellation_terms' => $quote->cancellation_terms,
        ])->all();
    }

    /**
     * The comparisons, done in PHP.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  Collection<int, Quote>  $quotes
     * @return array<string, mixed>
     */
    private function facts(array $rows, Collection $quotes): array
    {
        $totals = collect($rows)->pluck('total_amount')->map(fn ($value): float => (float) $value);
        $lowest = $totals->min();
        $highest = $totals->max();

        $cheapest = collect($rows)->first(fn (array $row): bool => (float) $row['total_amount'] === $lowest);

        // Only quotes that actually committed to a date can be compared on speed.
        $dated = collect($rows)->filter(fn (array $row): bool => $row['estimated_delivery_date'] !== null || $row['estimated_completion_date'] !== null);

        $fastest = $dated
            ->sortBy(fn (array $row): string => (string) ($row['estimated_delivery_date'] ?? $row['estimated_completion_date']))
            ->first();

        return [
            'lowest_total' => (string) $lowest,
            'highest_total' => (string) $highest,
            // The spread is the number that tells a buyer whether the quotes are even
            // comparable — a 3x range usually means the scope was read differently.
            'spread' => (string) round($highest - $lowest, 2),
            'spread_percentage' => $lowest > 0 ? round((($highest - $lowest) / $lowest) * 100, 1) : null,
            'cheapest_quote_number' => $cheapest['quote_number'] ?? null,
            'fastest_quote_number' => $fastest['quote_number'] ?? null,
            'expired_count' => collect($rows)->where('is_expired', true)->count(),
            'verified_supplier_count' => collect($rows)->where('supplier_verified', true)->count(),
            'quotes_without_dates' => collect($rows)->count() - $dated->count(),
            'quotes_without_warranty' => collect($rows)->filter(fn (array $r): bool => empty($r['warranty_terms']))->count(),
            'quotes_without_payment_terms' => collect($rows)->filter(fn (array $r): bool => empty($r['payment_terms']))->count(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $facts
     */
    private function narrate(Requirement $requirement, array $rows, array $facts, ?User $user): ?string
    {
        $payload = json_encode([
            'requirement' => $requirement->title,
            'computed_facts' => $facts,
            'quotes' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            return trim($this->ai->summarize(
                input: (string) $payload,
                user: $user,
                feature: 'quote_comparison',
                instructions: <<<'PROMPT'
                Write a short neutral comparison for the buyer: three or four sentences, or up to
                five tight bullets.

                Cover what genuinely differs — price, delivery dates, warranty, payment terms,
                supplier verification and rating — and call out anything missing from a quote,
                such as no warranty stated or no delivery date.

                Rules:
                - Use only the figures in computed_facts and the quote rows. Never calculate,
                  convert or estimate a number yourself.
                - Do NOT recommend a quote or say which is best value. Present the trade-offs.
                - If the price spread is large, say so and suggest the scope may have been read
                  differently, since that is usually why.
                - A null supplier rating means "no ratings yet", not a poor rating.
                PROMPT,
            )) ?: null;
        } catch (AiException) {
            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $facts
     */
    private function deterministicSummary(array $rows, array $facts): string
    {
        $parts = [sprintf('%d %s to compare.', count($rows), count($rows) === 1 ? 'quote' : 'quotes')];

        if ($facts['spread_percentage'] !== null && $facts['spread_percentage'] > 0) {
            $parts[] = sprintf(
                'Totals range from %s to %s, a spread of %s%%.',
                $facts['lowest_total'],
                $facts['highest_total'],
                $facts['spread_percentage'],
            );
        }

        if ($facts['quotes_without_dates'] > 0) {
            $parts[] = sprintf('%d gave no delivery date.', $facts['quotes_without_dates']);
        }

        if ($facts['expired_count'] > 0) {
            $parts[] = sprintf('%d has expired.', $facts['expired_count']);
        }

        return implode(' ', $parts);
    }
}
