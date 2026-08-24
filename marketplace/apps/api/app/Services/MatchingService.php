<?php

namespace App\Services;

use App\Enums\MatchResponseStatus;
use App\Enums\RequirementStatus;
use App\Models\Business;
use App\Models\Requirement;
use App\Models\RequirementMatch;
use App\Services\Matching\BusinessMatchScorer;
use App\Services\Matching\MatchScore;
use App\Services\Matching\RequirementMatchContext;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 4 — the business matching engine.
 *
 * Owns the whole Requirement -> Matching Engine -> Suitable Businesses flow:
 * picking a candidate pool, scoring it (delegated to BusinessMatchScorer),
 * persisting the resulting invitations, and the invitation lifecycle a
 * business drives afterwards (view / accept / decline / ask a question).
 *
 * Mirrors the existing service conventions: controllers stay thin, every
 * transition is an explicit method with its own pre-conditions, rule
 * violations raise ValidationException (rendered as 422), and multi-row
 * writes run inside a transaction.
 *
 * The algorithm is rule based and deterministic by design — no AI, and no
 * hardcoded business, city, category or industry anywhere in the pipeline.
 * Quotations and pricing negotiation are explicitly out of scope: accepting
 * a match expresses interest only.
 */
class MatchingService
{
    private const MATCH_RELATIONS = [
        'business.location',
        'business.category',
        'business.industries',
        'business.serviceAreas',
    ];

    public function __construct(
        private readonly BusinessMatchScorer $scorer,
        private readonly EnquiryService $enquiryService,
    ) {}

    /**
     * Run the engine for a requirement and persist the invitations.
     *
     * Idempotent: re-running refreshes each match's score and explanation but
     * preserves invited_at, viewed_at and any response already given. A
     * business that no longer qualifies keeps its existing invitation rather
     * than having it silently withdrawn — it may already have replied, and
     * revoking an invitation mid-conversation would be worse than a stale
     * match. Nothing is ever deleted here.
     *
     * @return EloquentCollection<int, RequirementMatch>
     */
    public function generateMatches(Requirement $requirement): EloquentCollection
    {
        if (! $requirement->isMatchable()) {
            throw ValidationException::withMessages([
                'status' => ['Only a published requirement can be matched with businesses.'],
            ]);
        }

        $context = RequirementMatchContext::for($requirement);
        $scored = $this->scoreCandidates($requirement, $context);

        DB::transaction(function () use ($requirement, $scored): void {
            foreach ($scored as ['business' => $business, 'score' => $score]) {
                /** @var MatchScore $score */
                $match = RequirementMatch::firstOrNew([
                    'requirement_id' => $requirement->id,
                    'business_id' => $business->id,
                ]);

                if (! $match->exists) {
                    $match->invited_at = now();
                    $match->response_status = MatchResponseStatus::Pending;
                }

                $match->match_score = $score->total;
                $match->match_reason = $score->toReasonPayload();
                $match->save();
            }

            // Published -> Matching is the engine reporting that the
            // marketplace has found somebody to show the customer. It only
            // ever moves forward from Published; a requirement already
            // receiving quotes is not dragged back a stage.
            if ($requirement->status === RequirementStatus::Published && $scored !== []) {
                $requirement->status = RequirementStatus::Matching;
                $requirement->save();
            }
        });

        return $this->matchesFor($requirement);
    }

    /**
     * Matching runs automatically when a requirement is published — the
     * customer should never have to ask the marketplace to look. Failures
     * here must not take the publish with them: publishing succeeded, and a
     * match run can always be repeated, so the error is swallowed and left
     * for the next run rather than shown as a failed publish.
     */
    public function generateMatchesQuietly(Requirement $requirement): void
    {
        try {
            $this->generateMatches($requirement);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Persisted matches for a requirement, best first. This is the customer's
     * result set — it reads stored scores and never re-scores, so what the
     * customer sees is exactly what the engine decided.
     *
     * @return EloquentCollection<int, RequirementMatch>
     */
    public function matchesFor(Requirement $requirement): EloquentCollection
    {
        return $requirement->matches()
            ->with(self::MATCH_RELATIONS)
            ->ranked()
            ->get();
    }

    /**
     * Score every candidate without writing anything — used by the engine
     * itself and safe for previewing/tuning.
     *
     * @return array<int, array{business: Business, score: MatchScore}>
     */
    public function scoreCandidates(Requirement $requirement, ?RequirementMatchContext $context = null): array
    {
        $context ??= RequirementMatchContext::for($requirement);

        $minimumScore = (float) config('marketplace.matching.minimum_score', 0);
        $maxMatches = (int) config('marketplace.matching.max_matches', 20);

        $scored = $this->candidateBusinesses($requirement, $context)
            ->map(fn (Business $business) => [
                'business' => $business,
                'score' => $this->scorer->score($context, $business),
            ])
            ->filter(fn (array $entry) => $entry['score']->meetsThreshold($minimumScore))
            // Deterministic ordering: score descending, then business id
            // ascending so equal scores never shuffle between runs.
            ->sort(fn (array $a, array $b) => [$b['score']->total, $a['business']->id] <=> [$a['score']->total, $b['business']->id])
            ->values();

        return ($maxMatches > 0 ? $scored->take($maxMatches) : $scored)->all();
    }

    /**
     * The pool worth scoring: businesses with any plausible connection to the
     * requirement's category, either declared as their primary category or
     * demonstrated through active products/services or published listings.
     *
     * Unavailable businesses are deliberately left in the pool rather than
     * filtered out — availability is a weighted dimension, not a gate, so the
     * configured minimum score decides whether they still make the cut.
     *
     * @return Collection<int, Business>
     */
    private function candidateBusinesses(Requirement $requirement, RequirementMatchContext $context): Collection
    {
        $categoryIds = array_values(array_unique([$context->categoryId, ...$context->relatedCategoryIds]));
        $limit = (int) config('marketplace.matching.candidate_limit', 300);

        return Business::query()
            ->where(function ($query) use ($categoryIds): void {
                $query->whereIn('category_id', $categoryIds)
                    ->orWhereHas('products', fn ($q) => $q->where('is_active', true)->whereIn('category_id', $categoryIds))
                    ->orWhereHas('services', fn ($q) => $q->where('is_active', true)->whereIn('category_id', $categoryIds))
                    ->orWhereHas('listings', fn ($q) => $q->published()->whereIn('category_id', $categoryIds));
            })
            // A customer never gets matched with their own business.
            ->where('user_id', '!=', $requirement->customer_id)
            ->with(BusinessMatchScorer::REQUIRED_RELATIONS)
            ->withCount([
                'requirementMatches as invited_matches_count' => fn ($q) => $q->whereNotNull('invited_at'),
                'requirementMatches as responded_matches_count' => fn ($q) => $q->where('response_status', '!=', MatchResponseStatus::Pending->value),
            ])
            // The candidate cap is a safety valve, so order it by cheap
            // relevance proxies first — if a very broad category ever hits the
            // limit, the businesses dropped are the least likely to have won.
            ->orderByRaw('CASE WHEN category_id = ? THEN 0 ELSE 1 END', [$context->categoryId])
            ->orderByDesc('is_verified')
            ->orderByDesc('rating')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /* ------------------------------------------------------------------ *
     | Invitation lifecycle — driven by the matched business
     * ------------------------------------------------------------------ */

    /**
     * First open by the business. Recorded once: viewed_at is "when did they
     * first see this", so a second visit doesn't overwrite it.
     */
    public function markViewed(RequirementMatch $match): RequirementMatch
    {
        if ($match->viewed_at === null) {
            $match->viewed_at = now();
            $match->save();
        }

        return $match;
    }

    /**
     * Business signals it can help. Interest only — no commercial terms, no
     * quote.
     *
     * Phase 5: accepting an invitation also opens and accepts the enquiry, so
     * the business can go straight on to quoting and the two records never
     * disagree about whether the pair is engaging. EnquiryService skips its own
     * match sync because the match has already responded by then, so there is
     * no loop between the two services.
     */
    public function accept(RequirementMatch $match): RequirementMatch
    {
        $responded = $this->respond($match, MatchResponseStatus::Accepted, null);

        $enquiry = $this->enquiryService->ensureForMatch($match);
        if ($enquiry->isPending()) {
            $this->enquiryService->accept($enquiry);
        }

        return $responded;
    }

    /** Business passes on the requirement, optionally saying why. */
    public function decline(RequirementMatch $match, ?string $note = null): RequirementMatch
    {
        $responded = $this->respond($match, MatchResponseStatus::Declined, $note);

        $enquiry = $this->enquiryService->ensureForMatch($match);
        if ($enquiry->isPending()) {
            $this->enquiryService->decline($enquiry, $note);
        }

        return $responded;
    }

    /**
     * A business asks the customer to clarify something. Deliberately leaves
     * the response Pending — asking is not deciding, and a business shouldn't
     * have to commit before it understands the requirement.
     */
    public function askQuestion(RequirementMatch $match, string $question): RequirementMatch
    {
        if ($match->response_status === MatchResponseStatus::Declined) {
            throw ValidationException::withMessages([
                'question' => ['You have already declined this requirement.'],
            ]);
        }

        $this->assertRequirementStillOpen($match);

        $match->question = $question;
        $match->question_asked_at = now();
        if ($match->viewed_at === null) {
            $match->viewed_at = now();
        }
        $match->save();

        return $match->fresh(self::MATCH_RELATIONS);
    }

    private function respond(RequirementMatch $match, MatchResponseStatus $status, ?string $note): RequirementMatch
    {
        if ($match->hasResponded()) {
            throw ValidationException::withMessages([
                'response_status' => [sprintf('You have already %s this requirement.', strtolower($match->response_status->label()))],
            ]);
        }

        $this->assertRequirementStillOpen($match);

        $match->response_status = $status;
        $match->responded_at = now();
        $match->response_note = $note;
        if ($match->viewed_at === null) {
            $match->viewed_at = now();
        }
        $match->save();

        return $match->fresh(self::MATCH_RELATIONS);
    }

    /**
     * A closed or cancelled requirement is not something to respond to. The
     * policy already blocks the wrong *user*; this blocks the wrong *moment*.
     */
    private function assertRequirementStillOpen(RequirementMatch $match): void
    {
        $match->loadMissing('requirement');

        if ($match->requirement === null || ! $match->requirement->isMatchable()) {
            throw ValidationException::withMessages([
                'requirement' => ['This requirement is no longer open for responses.'],
            ]);
        }
    }
}
