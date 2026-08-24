<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Ai\AiDisabledException;
use App\Exceptions\Ai\AiException;
use App\Http\Controllers\Controller;
use App\Models\Enquiry;
use App\Models\Requirement;
use App\Models\Review;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\Intelligence\ListingGenerator;
use App\Services\Ai\Intelligence\MatchExplainer;
use App\Services\Ai\Intelligence\QuoteAssistant;
use App\Services\Ai\Intelligence\QuoteComparisonSummariser;
use App\Services\Ai\Intelligence\ResponseDrafter;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 13 — the AI intelligence endpoints.
 *
 * Every one of these is read-and-suggest. None writes a domain record: the match
 * explainer reads stored scores, the quote comparison reads stored quotes, and the three
 * generators return text for a human to review and submit through the ordinary
 * endpoints.
 *
 * Authorization deliberately reuses the existing policies (`RequirementPolicy`,
 * `EnquiryPolicy`, `ReviewPolicy`) via `$this->authorize()`. The AI layer adds no second
 * permission system, because two systems drift and the drift is always in the permissive
 * direction.
 */
class AiIntelligenceController extends Controller
{
    public function __construct(
        private readonly MatchExplainer $matches,
        private readonly QuoteComparisonSummariser $quotes,
        private readonly ListingGenerator $listings,
        private readonly ResponseDrafter $responses,
        private readonly QuoteAssistant $quoteAssistant,
        private readonly AiProviderManager $providers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Explain why these suppliers were matched.
     *
     * Notably this endpoint works with AI disabled: the structured explanation and the
     * deterministic summary come from stored match data, and only the narrative needs a
     * provider. Turning AI off degrades the prose, not the feature.
     */
    public function explainMatches(Request $request, Requirement $requirement): JsonResponse
    {
        // The requirement owner, or a business invited to it, may see this.
        $this->authorize('viewMatches', $requirement);

        $result = $this->matches->explainForRequirement(
            $requirement,
            $request->user(),
            withNarrative: $this->providers->isEnabled('matching'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Match explanation retrieved successfully.',
            'data' => $result,
            'meta' => [
                'used_ai' => $result['used_ai'],
                // The point worth making to any client of this endpoint.
                'note' => 'Scores and reasons come from the deterministic matching engine, not from AI.',
            ],
        ]);
    }

    /** Compare the quotes on a requirement. Customer-only, and only their own. */
    public function compareQuotes(Request $request, Requirement $requirement): JsonResponse
    {
        $this->authorize('view', $requirement);
        abort_unless($request->user()->id === $requirement->customer_id, 403, 'Only the buyer can compare these quotes.');

        return response()->json([
            'success' => true,
            'message' => 'Quote comparison retrieved successfully.',
            'data' => $this->quotes->compare($requirement, $request->user()),
        ]);
    }

    /**
     * Generate listing copy from a short brief.
     *
     * Returns a draft only. The owner still creates the listing through the normal form,
     * and it still passes through draft → pending_review → admin approval.
     */
    public function generateListing(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isOwner(), 403, 'Only a business owner can generate a listing.');

        $business = $user->business;
        abort_if($business === null, 404, 'Create your business profile first.');

        $validated = $request->validate([
            'brief' => ['required', 'string', 'min:10', 'max:'.(int) config('ai.limits.max_input_characters', 4000)],
        ]);

        if (! $this->providers->isEnabled('listing_generator')) {
            return $this->unavailable(new AiDisabledException('listing_generator'), 'The standard listing form is unaffected.');
        }

        try {
            $result = $this->listings->generate($business, $validated['brief'], $user);
        } catch (AiException $exception) {
            return $this->unavailable($exception, 'The standard listing form is unaffected.');
        }

        // Generating marketing copy on a business's behalf is worth a trail, so an owner
        // can later see what was AI-drafted.
        $this->audit->log('ai.listing.generated', $business, $user, [
            'category' => $result['draft']['category'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Listing draft generated. Review and edit before creating the listing.',
            'data' => $result['draft'],
            'meta' => $result['meta'],
        ]);
    }

    /** Draft a reply to an enquiry. The owner sends it themselves. */
    public function draftEnquiryReply(Request $request, Enquiry $enquiry): JsonResponse
    {
        $this->authorize('respond', $enquiry);

        $validated = $request->validate([
            // Lets the owner steer: "decline politely", "ask about site access".
            'intent' => ['nullable', 'string', 'max:300'],
        ]);

        if (! $this->providers->isEnabled('response_assistant')) {
            return $this->unavailable(new AiDisabledException('response_assistant'), 'You can reply manually as normal.');
        }

        try {
            $result = $this->responses->draftEnquiryReply($enquiry, $request->user(), $validated['intent'] ?? null);
        } catch (AiException $exception) {
            return $this->unavailable($exception, 'You can reply manually as normal.');
        }

        return response()->json([
            'success' => true,
            'message' => 'Draft reply generated. Nothing has been sent.',
            'data' => $result['draft'],
            'meta' => $result['meta'],
        ]);
    }

    /** Draft the single public response to a review. */
    public function draftReviewResponse(Request $request, Review $review): JsonResponse
    {
        $this->authorize('respond', $review);

        if (! $this->providers->isEnabled('response_assistant')) {
            return $this->unavailable(new AiDisabledException('response_assistant'), 'You can write your response manually.');
        }

        try {
            $result = $this->responses->draftReviewResponse($review, $request->user());
        } catch (AiException $exception) {
            return $this->unavailable($exception, 'You can write your response manually.');
        }

        return response()->json([
            'success' => true,
            'message' => 'Draft response generated. Nothing has been published.',
            'data' => $result['draft'],
            'meta' => $result['meta'],
        ]);
    }

    /**
     * Suggest a quote structure for an enquiry.
     *
     * Returns line-item descriptions, missing information and questions — never a figure.
     * The owner prices it and QuoteCalculator computes the totals from their inputs.
     */
    public function suggestQuoteStructure(Request $request, Enquiry $enquiry): JsonResponse
    {
        /*
         * `createQuote`, not `respond`. Replying is something a supplier does to a
         * pending enquiry; quoting requires it to have been accepted first. Using the
         * narrower ability means the assistant cannot help structure a quote for an
         * enquiry that is not yet quotable, which is the same rule the quote builder
         * enforces.
         */
        $this->authorize('createQuote', $enquiry);

        if (! $this->providers->isEnabled('quote_comparison')) {
            return $this->unavailable(new AiDisabledException('quote_comparison'), 'The quote builder is unaffected.');
        }

        try {
            $result = $this->quoteAssistant->suggestStructure($enquiry, $request->user());
        } catch (AiException $exception) {
            return $this->unavailable($exception, 'The quote builder is unaffected.');
        }

        return response()->json([
            'success' => true,
            'message' => 'Quote structure suggested. You set all pricing.',
            'data' => $result['suggestion'],
            'meta' => $result['meta'],
        ]);
    }

    private function unavailable(AiException $exception, string $fallback): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $exception->userMessage(),
            'errors' => ['ai' => [$exception->errorCode()]],
            'meta' => ['fallback' => $fallback],
        ], $exception->httpStatus());
    }
}
