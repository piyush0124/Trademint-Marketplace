<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AiConversationType;
use App\Exceptions\Ai\AiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiConversationResource;
use App\Http\Resources\AiMessageResource;
use App\Models\AiConversation;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiUsageTracker;
use App\Services\Ai\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 11 — the AI assistant HTTP surface.
 *
 * Thin by design: every decision that matters (which tools exist, who may use them,
 * what gets confirmed) lives in the service layer, exactly as the marketplace's other
 * controllers delegate to their services.
 *
 * Every conversation is scoped to `auth()->user()`. There is no route that takes a
 * user id, so one account cannot read another's transcript by changing a path
 * segment — the ownership check is a query scope, not a comparison someone can forget.
 */
class AiConversationController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly AiProviderManager $providers,
        private readonly AiUsageTracker $usage,
    ) {}

    /**
     * Whether AI is usable, and how much of the allowance is left.
     *
     * Deliberately cheap and never failing. The frontend calls this before rendering
     * any AI affordance, so it has to answer honestly and quickly even when the
     * provider is down — that is what makes the fallback silent rather than a broken
     * button.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'message' => 'AI status retrieved successfully.',
            'data' => array_merge($this->providers->status(), [
                'daily_limit' => $this->usage->dailyLimitFor($user),
                'used_today' => $this->usage->usedToday($user),
                // Null means unlimited rather than a misleadingly large number.
                'remaining_today' => $this->usage->remainingToday($user),
                'conversation_types' => collect(AiConversationType::cases())
                    ->filter(fn (AiConversationType $type): bool => $type->permits($user->role))
                    ->map(fn (AiConversationType $type): array => [
                        'value' => $type->value,
                        'label' => $type->label(),
                        'enabled' => $this->providers->isEnabled($type->feature()),
                    ])
                    ->values()
                    ->all(),
            ]),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $conversations = AiConversation::query()
            ->forUser($request->user()->id)
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Conversations retrieved successfully.',
            'data' => AiConversationResource::collection($conversations),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', Rule::in(AiConversationType::values())],
            'title' => ['nullable', 'string', 'max:120'],
            // Small scoping hints only. Bounded so a client cannot smuggle a payload
            // into the thread that later ends up in a prompt.
            'context' => ['nullable', 'array', 'max:10'],
            'context.*' => ['nullable', 'string', 'max:200'],
        ]);

        $type = $validated['type'] !== null && isset($validated['type'])
            ? AiConversationType::from($validated['type'])
            : AiConversationType::defaultFor($request->user()->role);

        try {
            $conversation = $this->conversations->start(
                $request->user(),
                $type,
                $validated['title'] ?? null,
                $validated['context'] ?? [],
            );
        } catch (AiException $exception) {
            return $this->aiFailure($exception);
        }

        return response()->json([
            'success' => true,
            'message' => 'Conversation started.',
            'data' => new AiConversationResource($conversation->load('messages')),
        ], 201);
    }

    public function show(Request $request, AiConversation $conversation): JsonResponse
    {
        $this->assertOwner($request, $conversation);

        return response()->json([
            'success' => true,
            'message' => 'Conversation retrieved successfully.',
            'data' => new AiConversationResource($conversation->load(['messages', 'actions'])),
        ]);
    }

    /**
     * Send a message and get the assistant's reply.
     *
     * Note what happens on AI failure: a 503 with a plain explanation, and the
     * marketplace is entirely unaffected. This endpoint failing must never be more
     * than a disappointment.
     */
    public function sendMessage(Request $request, AiConversation $conversation): JsonResponse
    {
        $this->assertOwner($request, $conversation);

        $validated = $request->validate([
            'message' => [
                'required', 'string', 'min:1',
                // Mirrors the service-level truncation so a caller gets a clear 422
                // rather than silently having their message cut.
                'max:'.(int) config('ai.limits.max_input_characters', 4000),
            ],
        ]);

        try {
            $result = $this->conversations->send($conversation, $request->user(), $validated['message']);
        } catch (AiException $exception) {
            return $this->aiFailure($exception);
        }

        return response()->json([
            'success' => true,
            'message' => 'Reply generated.',
            'data' => new AiMessageResource($result['message']),
            'meta' => [
                'remaining_today' => $this->usage->remainingToday($request->user()),
                // Surfaced separately so the UI can render a confirmation card even if
                // it does not parse message metadata.
                'pending_actions' => collect($result['tool_results'])
                    ->filter(fn ($toolResult): bool => $toolResult->isAwaitingConfirmation())
                    ->map(fn ($toolResult): array => [
                        'uuid' => $toolResult->pendingActionUuid,
                        'tool' => $toolResult->tool,
                        'summary' => $toolResult->confirmationSummary,
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function destroy(Request $request, AiConversation $conversation): JsonResponse
    {
        $this->assertOwner($request, $conversation);

        // The thread is the user's own; deleting it is a privacy affordance. The
        // ai_requests cost ledger and any audit entries survive, because those are
        // operational records rather than personal content.
        $conversation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Conversation deleted.',
            'data' => null,
        ]);
    }

    private function assertOwner(Request $request, AiConversation $conversation): void
    {
        // 404 rather than 403: whether someone else's conversation exists is not
        // information this endpoint should confirm.
        abort_unless($conversation->user_id === $request->user()->id, 404, 'Conversation not found.');
    }

    private function aiFailure(AiException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            // Written by us, never the provider's own text, which can carry request
            // ids and occasionally credential fragments.
            'message' => $exception->userMessage(),
            'errors' => ['ai' => [$exception->errorCode()]],
        ], $exception->httpStatus());
    }
}
