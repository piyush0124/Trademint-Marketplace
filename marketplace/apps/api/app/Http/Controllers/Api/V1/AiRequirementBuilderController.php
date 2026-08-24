<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Ai\AiDisabledException;
use App\Exceptions\Ai\AiException;
use App\Http\Controllers\Controller;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\Extraction\RequirementExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 12 — the AI requirement builder.
 *
 * One endpoint, and it deliberately does not write anything. It reads a plain
 * description and returns a *proposal*: extracted fields, per-field confidence, real
 * category options where the match was ambiguous, and the questions still worth asking.
 *
 * The customer then reviews it and submits through the ordinary requirement endpoints,
 * or confirms the AI's `create_requirement_draft` action. Either way a human sees the
 * fields before anything is saved, and nothing is ever published automatically —
 * publishing exposes a buyer to suppliers, and that is not a decision to hand to an
 * extraction.
 *
 * Customer-only: businesses do not post requirements.
 */
class AiRequirementBuilderController extends Controller
{
    public function __construct(
        private readonly RequirementExtractor $extractor,
        private readonly AiProviderManager $providers,
    ) {}

    public function extract(Request $request): JsonResponse
    {
        abort_unless($request->user()->isCustomer(), 403, 'Only customers can post requirements.');

        $validated = $request->validate([
            'input' => ['required', 'string', 'min:5', 'max:'.(int) config('ai.limits.max_input_characters', 4000)],
        ]);

        if (! $this->providers->isEnabled('requirement_builder')) {
            // A clear 503 rather than a silent empty result, so the frontend can point
            // the buyer at the manual form — which always remains available.
            return $this->unavailable(new AiDisabledException('requirement_builder'));
        }

        try {
            $result = $this->extractor->extract($validated['input'], $request->user());
        } catch (AiException $exception) {
            return $this->unavailable($exception);
        }

        return response()->json([
            'success' => true,
            'message' => $result['ready_to_draft']
                ? 'Requirement details extracted. Review before creating the draft.'
                : 'Partial details extracted. A little more information is needed.',
            'data' => $result['draft'],
            'meta' => [
                // Per-field, so the UI can highlight what to check rather than asking
                // the buyer to re-read everything.
                'confidence' => $result['confidence'],
                'resolution' => $result['resolution'],
                // Real rows only — a "did you mean" picker that cannot offer a
                // non-existent category.
                'category_options' => $result['category_options'],
                'location_options' => $result['location_options'],
                'missing' => $result['missing'],
                'questions' => $result['questions'],
                'ready_to_draft' => $result['ready_to_draft'],
                'ready_to_publish' => $result['ready_to_publish'],
                'note' => 'Nothing has been saved. Review the fields, then create the draft yourself.',
            ],
        ]);
    }

    private function unavailable(AiException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $exception->userMessage(),
            'errors' => ['ai' => [$exception->errorCode()]],
            'meta' => [
                // The explicit fallback instruction. AI being down must never look like
                // the marketplace being down.
                'fallback' => 'The standard requirement form is unaffected and can be used as normal.',
            ],
        ], $exception->httpStatus());
    }
}
