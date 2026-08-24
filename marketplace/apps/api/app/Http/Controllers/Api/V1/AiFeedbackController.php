<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiFeedback;
use App\Models\AiMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Was that answer any good?
 *
 * A two-way vote, one per user per message, on their own conversations only. This is the
 * only signal the platform gets about whether the assistant is actually helping — token
 * counts measure cost, not usefulness — and it is what would inform a prompt change or a
 * model downgrade.
 *
 * The vote is upserted rather than duplicated, so a user can change their mind without
 * stuffing the count. The voter is always the authenticated user, never a body field.
 */
class AiFeedbackController extends Controller
{
    public function store(Request $request, AiMessage $message): JsonResponse
    {
        $validated = $request->validate([
            // Two-way. A five-point scale on a chat reply gets used as a binary anyway.
            'rating' => ['required', 'integer', 'in:-1,1'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $message->loadMissing('conversation');

        // Scoped through the conversation's owner. 404 rather than 403 so the existence
        // of another user's message is not confirmed.
        abort_unless(
            $message->conversation !== null && $message->conversation->user_id === $request->user()->id,
            404,
            'Message not found.'
        );

        // Only an assistant turn is worth rating; the user's own message and a tool
        // result are not opinions the platform can act on.
        abort_unless($message->isAssistant(), 422, 'Only assistant replies can be rated.');

        /*
         * `firstOrNew` + `forceFill` rather than `updateOrCreate`, because `user_id` is
         * deliberately not fillable — the voter must come from the session, never a
         * request body. updateOrCreate would silently drop it and hit the NOT NULL
         * constraint, so the guard is doing its job; this is how to satisfy it honestly.
         */
        $feedback = AiFeedback::query()->firstOrNew([
            'ai_message_id' => $message->id,
            'user_id' => $request->user()->id,
        ]);

        $feedback->forceFill([
            'ai_message_id' => $message->id,
            'user_id' => $request->user()->id,
            'rating' => (int) $validated['rating'],
            'comment' => $validated['comment'] ?? null,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Thanks for the feedback.',
            'data' => [
                'message_id' => $message->id,
                'rating' => $feedback->rating,
                'is_positive' => $feedback->isPositive(),
            ],
        ]);
    }
}
