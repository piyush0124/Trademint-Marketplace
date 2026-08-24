<?php

namespace App\Services\Ai\Intelligence;

use App\Exceptions\Ai\AiException;
use App\Models\Requirement;
use App\Models\RequirementMatch;
use App\Models\User;
use App\Services\Ai\AiService;
use Illuminate\Support\Collection;

/**
 * Phase 13 — explains matches the deterministic engine already produced.
 *
 * The division of labour is the whole design, and it is worth being explicit about
 * because it is easy to get wrong: **the AI does not match.** The Phase 4
 * `BusinessMatchScorer` scores six weighted dimensions and persists the score, the
 * per-dimension breakdown and the reason lines into `requirement_matches.match_reason`.
 * This class reads those stored facts and, optionally, narrates them.
 *
 * That means:
 *  - the percentage shown is reproducible from the factors that produced it;
 *  - turning AI off changes the prose, never the ranking;
 *  - the AI cannot promote a supplier, because it never sees the candidate pool.
 *
 * The structured explanation is built with no AI call at all. The narrative is a bonus
 * layer on top, and if the provider is down the structured version still renders.
 */
class MatchExplainer
{
    public function __construct(private readonly AiService $ai) {}

    /**
     * Explain every match on a requirement.
     *
     * @return array<string, mixed>
     */
    public function explainForRequirement(Requirement $requirement, ?User $user = null, bool $withNarrative = true): array
    {
        $matches = $requirement->matches()
            ->with(['business:id,name,slug,rating,review_count,verification_status,years_in_business,location_id', 'business.location:id,city,state'])
            ->ranked()
            ->limit(10)
            ->get();

        if ($matches->isEmpty()) {
            return [
                'requirement' => $requirement->title,
                'count' => 0,
                'matches' => [],
                // Explicit, because an empty match list is a real state a buyer needs
                // explaining rather than an error.
                'summary' => 'No suppliers have been matched to this requirement yet.',
                'used_ai' => false,
            ];
        }

        $structured = $matches->map(fn (RequirementMatch $match): array => $this->structuredExplanation($match))->all();

        $narrative = null;
        $usedAi = false;

        if ($withNarrative) {
            $narrative = $this->narrate($requirement, $matches, $user);
            $usedAi = $narrative !== null;
        }

        return [
            'requirement' => $requirement->title,
            'count' => count($structured),
            'matches' => $structured,
            'summary' => $narrative ?? $this->deterministicSummary($matches),
            'used_ai' => $usedAi,
        ];
    }

    /**
     * One match, explained from stored data only.
     *
     * Every line here traces to a column or to `match_reason`, which was written by the
     * scorer at match time. Nothing is recomputed, so a later change to the configured
     * weights cannot retroactively alter the explanation a business already read.
     *
     * @return array<string, mixed>
     */
    public function structuredExplanation(RequirementMatch $match): array
    {
        $business = $match->business;

        return [
            'match_id' => $match->uuid,
            'business' => $business?->name,
            'business_slug' => $business?->slug,
            'city' => $business?->location?->city,
            'match_score' => $match->scorePercentage(),
            // The engine's own ordered "why matched" lines.
            'reasons' => $match->reasons(),
            // Per-dimension detail, so a 92% can be taken apart.
            'breakdown' => collect($match->breakdown())
                ->map(fn (array $dimension, string $key): array => [
                    'dimension' => $key,
                    'score' => round((float) ($dimension['score'] ?? 0) * 100),
                    'weight_percentage' => round((float) ($dimension['weight_percentage'] ?? 0)),
                    'reason' => $dimension['reason'] ?? null,
                ])
                ->values()
                ->all(),
            'matched_capabilities' => $match->matchedCapabilities(),
            'verified' => $business?->verificationStatus()->value === 'verified',
            // Null rather than 0 — an unrated supplier is unproven, not bad.
            'rating' => $business?->rating !== null ? (float) $business->rating : null,
            'review_count' => (int) ($business?->review_count ?? 0),
            'response_status' => $match->response_status->value,
        ];
    }

    /**
     * A plain-language summary, composed without AI.
     *
     * This is the fallback and it is deliberately good enough to ship on its own — the
     * narrative is an improvement in readability, not a dependency.
     *
     * @param  Collection<int, RequirementMatch>  $matches
     */
    private function deterministicSummary(Collection $matches): string
    {
        $verified = $matches->filter(fn (RequirementMatch $m): bool => $m->business?->verificationStatus()->value === 'verified')->count();
        $rated = $matches->filter(fn (RequirementMatch $m): bool => $m->business?->rating !== null)->count();
        $best = $matches->first()?->scorePercentage() ?? 0;

        $parts = [sprintf(
            '%d %s matched, scoring up to %d%%.',
            $matches->count(),
            $matches->count() === 1 ? 'supplier' : 'suppliers',
            $best,
        )];

        if ($verified > 0) {
            $parts[] = sprintf('%d %s platform-verified.', $verified, $verified === 1 ? 'is' : 'are');
        }

        $parts[] = $rated > 0
            ? sprintf('%d %s customer ratings.', $rated, $rated === 1 ? 'has' : 'have')
            : 'None have ratings yet, so judge them on verification and capability.';

        return implode(' ', $parts);
    }

    /**
     * Ask the model to narrate the stored facts.
     *
     * Only the already-extracted fields are sent — not the business records, not the
     * requirement description, not any contact detail. That keeps the payload small
     * (the single biggest lever on cost) and means a compromised prompt has nothing
     * private to leak.
     *
     * Returns null on any AI failure, and the caller falls back to the deterministic
     * summary. A match list must never fail to render because a provider is down.
     *
     * @param  Collection<int, RequirementMatch>  $matches
     */
    private function narrate(Requirement $requirement, Collection $matches, ?User $user): ?string
    {
        $facts = $matches->take(5)->map(fn (RequirementMatch $match): array => [
            'supplier' => $match->business?->name,
            'score' => $match->scorePercentage(),
            'reasons' => $match->reasons(),
            'verified' => $match->business?->verificationStatus()->value === 'verified',
            'rating' => $match->business?->rating !== null ? (float) $match->business->rating : null,
            'city' => $match->business?->location?->city,
        ])->all();

        $payload = json_encode([
            'requirement' => $requirement->title,
            'category' => $requirement->category?->name,
            'city' => $requirement->location?->city,
            'matches' => $facts,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            return trim($this->ai->summarize(
                input: (string) $payload,
                user: $user,
                feature: 'matching',
                instructions: <<<'PROMPT'
                Write two or three short sentences telling the buyer what this shortlist looks
                like and what distinguishes the top suppliers.

                Use only the supplied scores, reasons, cities, verification flags and ratings.
                Do not add a capability, price, timeline or claim that is not in the data. Do not
                tell the buyer which supplier to pick — describe the differences and let them
                decide. A null rating means "no ratings yet", never a low score.
                PROMPT,
            )) ?: null;
        } catch (AiException) {
            return null;
        }
    }
}
