<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiActionResource;
use App\Models\AiAction;
use App\Services\Ai\ToolRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Confirmation endpoints for AI-proposed write actions.
 *
 * This controller is the human in the loop. A write tool cannot execute during a
 * conversation turn — it can only leave a pending `ai_actions` row — so the only way
 * anything gets written is a deliberate, authenticated POST here.
 *
 * That separation is what makes the guarantee real. The model cannot forge this
 * request, cannot call it on the user's behalf, and cannot talk its way past it,
 * because it is not a tool: it is a different HTTP endpoint that only a browser
 * session reaches.
 */
class AiActionController extends Controller
{
    public function __construct(private readonly ToolRunner $runner) {}

    /** Pending proposals for the signed-in user. */
    public function index(Request $request): JsonResponse
    {
        $actions = AiAction::query()
            ->where('user_id', $request->user()->id)
            ->pending()
            ->latest('id')
            ->limit(20)
            ->get()
            // Expiry is enforced lazily: the TTL is short and a scheduled sweep for
            // rows nobody will ever look at again would be busywork.
            ->reject(fn (AiAction $action): bool => $action->hasExpired())
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Pending AI actions retrieved successfully.',
            'data' => AiActionResource::collection($actions),
        ]);
    }

    public function show(Request $request, AiAction $action): JsonResponse
    {
        $this->assertOwner($request, $action);

        return response()->json([
            'success' => true,
            'message' => 'AI action retrieved successfully.',
            'data' => new AiActionResource($action),
        ]);
    }

    /**
     * The user says yes — now it runs.
     *
     * Everything is re-checked inside `executeConfirmed`: arguments re-validated, the
     * tool re-authorised, expiry enforced. Minutes may have passed since the proposal
     * and the database may have moved, so the earlier checks are not trusted.
     */
    public function confirm(Request $request, AiAction $action): JsonResponse
    {
        $this->assertOwner($request, $action);

        $result = $this->runner->executeConfirmed($action, $request->user());

        if (! $result->ok) {
            return response()->json([
                'success' => false,
                'message' => $result->error ?? 'That action could not be completed.',
                'data' => new AiActionResource($action->fresh()),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Done.',
            'data' => new AiActionResource($action->fresh()),
            'meta' => ['result' => $result->data],
        ]);
    }

    /** The user says no. Recorded rather than deleted, so the decision is auditable. */
    public function reject(Request $request, AiAction $action): JsonResponse
    {
        $this->assertOwner($request, $action);

        if (! $action->isConfirmable()) {
            return response()->json([
                'success' => false,
                'message' => sprintf('This action is already %s.', strtolower($action->status->label())),
                'data' => new AiActionResource($action),
            ], 422);
        }

        $this->runner->reject($action, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Action declined.',
            'data' => new AiActionResource($action->fresh()),
        ]);
    }

    private function assertOwner(Request $request, AiAction $action): void
    {
        abort_unless($action->user_id === $request->user()->id, 404, 'Action not found.');
    }
}
